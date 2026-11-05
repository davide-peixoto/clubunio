<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\ChatImageCompression;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ChatImageCompressionTest extends TestCase
{
    private function bytes(\GdImage $image, string $format='jpeg', int $quality=95): string
    {
        ob_start();
        try {
            match($format) {
                'png' => imagepng($image),
                'webp' => imagewebp($image,null,$quality),
                default => imagejpeg($image,null,$quality),
            };
            return ob_get_contents();
        } finally { ob_end_clean(); imagedestroy($image); }
    }

    public function test_noisy_large_photo_is_bounded_reencoded_and_deterministic(): void
    {
        $image=imagecreatetruecolor(2400,1600); mt_srand(8123);
        for($y=0;$y<1600;$y++) for($x=0;$x<2400;$x++) imagesetpixel($image,$x,$y,mt_rand(0,0xffffff));
        $original=$this->bytes($image);
        $this->assertGreaterThan(524288,strlen($original));
        $this->assertLessThanOrEqual(5242880,strlen($original));
        $service=new ChatImageCompression;
        $one=$service->normalize($original,'image/jpeg'); $two=$service->normalize($original,'image/jpeg');
        $this->assertLessThanOrEqual(ChatImageCompression::TARGET_BYTES,strlen($one['bytes']));
        $this->assertLessThanOrEqual(1600,max($one['width'],$one['height']));
        $this->assertSame('image/jpeg',$one['mime']);
        $this->assertSame(hash('sha256',$one['bytes']),hash('sha256',$two['bytes']));
        $this->assertLessThan(strlen($original)/3,strlen($one['bytes']));
        $this->assertNotSame($original,$one['bytes']);
    }

    public function test_png_transparency_remains_transparent_webp(): void
    {
        $image=imagecreatetruecolor(800,600); imagealphablending($image,false); imagesavealpha($image,true);
        imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
        imagefilledrectangle($image,200,150,600,450,imagecolorallocatealpha($image,240,10,20,63));
        $result=(new ChatImageCompression)->normalize($this->bytes($image,'png'),'image/png');
        $this->assertSame(function_exists('imagewebp')?'image/webp':'image/png',$result['mime']);
        $decoded=imagecreatefromstring($result['bytes']);
        try {
            $this->assertSame(127,(imagecolorat($decoded,10,10)>>24)&127);
            $this->assertEqualsWithDelta(63,(imagecolorat($decoded,400,300)>>24)&127,2);
            $this->assertLessThanOrEqual(ChatImageCompression::TARGET_BYTES,strlen($result['bytes']));
        } finally { imagedestroy($decoded); }
    }

    public function test_jpeg_exif_rotation_is_applied_before_metadata_is_stripped(): void
    {
        $image=imagecreatetruecolor(120,80);
        imagefill($image,0,0,0x0000ff); imagefilledrectangle($image,0,0,59,79,0xff0000);
        $jpeg=$this->bytes($image);
        $tiff="II".pack('vV',42,8).pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0)."GPS-PRIVATE";
        $segment="Exif\0\0".$tiff;
        $jpeg=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($segment)+2).$segment.substr($jpeg,2);
        $result=(new ChatImageCompression)->normalize($jpeg,'image/jpeg');
        $this->assertSame([80,120],[$result['width'],$result['height']]);
        $this->assertStringNotContainsString('Exif',$result['bytes']);
        $this->assertStringNotContainsString('GPS-PRIVATE',$result['bytes']);
        $decoded=imagecreatefromstring($result['bytes']);
        try {
            $top=imagecolorsforindex($decoded,imagecolorat($decoded,40,20));
            $bottom=imagecolorsforindex($decoded,imagecolorat($decoded,40,100));
            $this->assertGreaterThan(180,$top['red']); $this->assertLessThan(70,$top['blue']);
            $this->assertGreaterThan(180,$bottom['blue']); $this->assertLessThan(70,$bottom['red']);
        } finally { imagedestroy($decoded); }
    }

    public function test_png_exif_orientation_and_opaque_output_are_normalized(): void
    {
        $image=imagecreatetruecolor(120,80); imagefill($image,0,0,0x00ff00);
        $png=$this->bytes($image,'png');
        $tiff="MM".pack('nN',42,8).pack('n',1).pack('nnNn',0x112,3,1,8)."\0\0".pack('N',0);
        $data='eXIf'.$tiff; $chunk=pack('N',strlen($tiff)).$data.pack('N',crc32($data));
        // eXIf belongs before IDAT, immediately after the PNG IHDR chunk.
        $png=substr($png,0,33).$chunk.substr($png,33);
        $result=(new ChatImageCompression)->normalize($png,'image/png');
        $this->assertSame([80,120],[$result['width'],$result['height']]);
        $this->assertSame('image/jpeg',$result['mime']);
        $this->assertStringNotContainsString('eXIf',$result['bytes']);
    }

    public function test_webp_exif_orientation_and_alpha_are_preserved_without_metadata(): void
    {
        $image=imagecreatetruecolor(120,80); imagealphablending($image,false); imagesavealpha($image,true);
        imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
        imagefilledrectangle($image,20,10,80,60,imagecolorallocatealpha($image,200,50,10,0));
        $webp=$this->bytes($image,'webp',90);
        $this->assertSame('VP8X',substr($webp,12,4));
        $webp[20]=chr(ord($webp[20])|8);
        $tiff="II".pack('vV',42,8).pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
        $webp.='EXIF'.pack('V',strlen($tiff)).$tiff.(strlen($tiff)%2 ? "\0" : '');
        $webp=substr_replace($webp,pack('V',strlen($webp)-8),4,4);
        $result=(new ChatImageCompression)->normalize($webp,'image/webp');
        $this->assertSame([80,120],[$result['width'],$result['height']]);
        $this->assertSame('image/webp',$result['mime']);
        $this->assertStringNotContainsString('EXIF',$result['bytes']);
        $decoded=imagecreatefromstring($result['bytes']);
        try { $this->assertSame(127,(imagecolorat($decoded,1,1)>>24)&127); }
        finally { imagedestroy($decoded); }
    }

    public function test_invalid_and_oversized_headers_are_rejected_before_large_decode(): void
    {
        $header="\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.pack('NNCCCCC',6000,5000,8,2,0,0,0);
        $header.=pack('N',crc32(substr($header,12))).pack('N',0).'IEND'.pack('N',crc32('IEND'));
        foreach([[$header,'image/png'],['not an image','image/jpeg'],[str_repeat('x',5242881),'image/jpeg']] as [$bytes,$mime]) {
            try { (new ChatImageCompression)->normalize($bytes,$mime); $this->fail('Invalid image accepted'); }
            catch(HttpException $error) { $this->assertSame(422,$error->getStatusCode()); }
        }
    }

    public function test_source_bytes_can_differ_even_when_canonical_image_bytes_are_equal(): void
    {
        $image=imagecreatetruecolor(120,80); imagefill($image,0,0,0x00ff00); $source=$this->bytes($image);
        $different=$source.'Different trailing source metadata';
        $service=new ChatImageCompression;
        $this->assertNotSame(hash('sha256',$source),hash('sha256',$different));
        $this->assertSame($service->normalize($source,'image/jpeg')['bytes'],$service->normalize($different,'image/jpeg')['bytes']);
    }
}
