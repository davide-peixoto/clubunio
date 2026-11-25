<?php
namespace Tests\Feature;

use App\ClubUnio\Services\ReactAssets;
use Tests\TestCase;

class ReactAssetsTest extends TestCase
{
    public function test_untrusted_missing_and_unknown_manifest_entries_use_legacy_scripts():void
    {
        $assets=app(ReactAssets::class);
        request()->attributes->set(ReactAssets::class,['dashboard'=>'../../.env','navigation'=>'https://invalid.test/code.js','facility'=>'js/react-ui/missing-file.js']);
        foreach(['dashboard','navigation','facility','unknown'] as $name)$this->assertNull($assets->entry($name));
        $html=view('clubunio.react-script',['entry'=>'dashboard','legacy'=>'/js/clubunio-dashboard.js?v=previous'])->render();
        $this->assertStringContainsString('src="/js/clubunio-dashboard.js?v=previous"',$html);
        $this->assertStringContainsString('defer',$html);$this->assertStringNotContainsString('type="module"',$html);
    }
    public function test_built_entry_is_local_versioned_and_loaded_as_module():void
    {
        $path=app(ReactAssets::class)->entry('dashboard');
        $this->assertNotNull($path);$this->assertFileExists(public_path($path));
        $html=view('clubunio.react-script',['entry'=>'dashboard','legacy'=>'/old.js'])->render();
        $this->assertStringContainsString('type="module"',$html);$this->assertStringNotContainsString('/old.js',$html);
        $this->assertStringContainsString('modulepreload',$html);
        $this->assertSame([],app(ReactAssets::class)->preloads('dashboard'));
    }
}
