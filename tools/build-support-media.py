"""Build reusable illustrated, narrated step guides from authored DE/EN catalogs.

Images are the default; --with-video explicitly opts into offline generation.
Video publication is currently paused. Windows offline speech, Pillow and ffmpeg. Never reads account data or contacts an AI.
Output is an instructional slide video, not a recording of product interaction.
"""
import argparse
import concurrent.futures
import hashlib
import html
import json
import os
from pathlib import Path
import subprocess
import tempfile
import wave

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
WORDS = {
    'de': {'app': 'App', 'web': 'Webverwaltung', 'guide': 'Schrittanleitung', 'note': 'Bedienung abhängig von Rolle und Vereinsfreigabe'},
    'en': {'app': 'App', 'web': 'Web administration', 'guide': 'Step-by-step guide', 'note': 'Available actions depend on role and club settings'},
}


def font(size, bold=False):
    return ImageFont.truetype(str(Path(os.environ['WINDIR']) / 'Fonts' / ('segoeuib.ttf' if bold else 'segoeui.ttf')), size)


def wrap(draw, text, face, width):
    lines = []
    for paragraph in text.splitlines():
        line = ''
        for word in paragraph.split():
            candidate = (line + ' ' + word).strip()
            if line and draw.textlength(candidate, font=face) > width:
                lines.append(line)
                line = word
            else:
                line = candidate
        lines.append(line)
    return lines


def slide(path, locale, surface, article, step=None):
    w = WORDS[locale]
    image = Image.new('RGB', (1280, 720), '#F4F6FB')
    draw = ImageDraw.Draw(image)
    draw.rectangle((0, 0, 1280, 12), fill='#164B8A')
    draw.text((52, 35), 'ClubUnio', fill='#164B8A', font=font(30, True))
    draw.text((1230, 42), w[surface] + ' · ' + w['guide'], anchor='ra', fill='#546477', font=font(23))
    y = 105
    for line in wrap(draw, article['title'], font(38, True), 1160):
        draw.text((52, y), line, fill='#18283F', font=font(38, True)); y += 48
    if step is None:
        items = [(str(i + 1), x['title']) for i, x in enumerate(article['steps'])]
        for number, title in items:
            draw.rounded_rectangle((52, y + 4, 95, y + 47), radius=14, fill='#DFE9F6')
            draw.text((73, y + 24), number, anchor='mm', fill='#164B8A', font=font(24, True))
            lines = wrap(draw, title, font(27), 1070)
            for line in lines:
                draw.text((115, y + 4), line, fill='#25354A', font=font(27)); y += 34
            y += 18
    else:
        index, item = step
        draw.text((52, y + 20), f"{index + 1} / {len(article['steps'])}", fill='#164B8A', font=font(28, True)); y += 78
        for line in wrap(draw, item['title'], font(32, True), 1140):
            draw.text((52, y), line, fill='#18283F', font=font(32, True)); y += 42
        y += 24
        for line in wrap(draw, item['body'], font(29), 1140):
            draw.text((52, y), line, fill='#384A60', font=font(29)); y += 39
    if y > 650:
        raise RuntimeError('Slide content exceeds readable area: ' + article['title'])
    draw.line((52, 663, 1228, 663), fill='#D6DFEA', width=2)
    draw.text((52, 681), w['note'], fill='#607086', font=font(19))
    image.save(path, optimize=True)


def stamp(seconds):
    ms = round(seconds * 1000)
    return f'{ms // 3600000:02}:{ms // 60000 % 60:02}:{ms // 1000 % 60:02}.{ms % 1000:03}'


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument('--php', default=r'C:\xampp\php\php.exe')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--images-only', action='store_true', help='Compatibility option; images are the default')
    parser.add_argument('--with-video', action='store_true', help='Explicit offline video generation; not part of the current release')
    parser.add_argument('--workers', type=int, default=3)
    args = parser.parse_args()
    args.images_only = args.images_only or not args.with_video
    catalogs = json.loads(subprocess.check_output([args.php, str(ROOT / 'tools/export-support-guides.php')]))
    work = Path(tempfile.mkdtemp(prefix='clubunio-wiki-render-'))
    tasks = []
    reused = []
    speech = []
    for locale, surfaces in catalogs.items():
        for surface, articles in surfaces.items():
            for key, article in articles.items():
                out = args.output / locale / surface
                out.mkdir(parents=True, exist_ok=True)
                source_hash = hashlib.sha256(json.dumps(article, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
                sidecar = out / (key + '.source.json')
                if not args.images_only and sidecar.exists():
                    previous = json.loads(sidecar.read_text(encoding='utf-8'))
                    if previous.get('source_sha256') == source_hash and all((out / (key + ext)).exists() for ext in ['.png', '.mp4', '.vtt', '.html']):
                        reused.append(previous)
                        continue
                folder = work / locale / surface / key
                folder.mkdir(parents=True)
                slide(out / (key + '.png'), locale, surface, article)
                for index, step in enumerate([] if args.images_only else article['steps']):
                    slide(folder / f'{index}.png', locale, surface, article, (index, step))
                    text = (article['title'] + '. ' if index == 0 else '') + step['title'] + '. ' + step['body']
                    speech.append({'locale': locale, 'text': text, 'path': str(folder / f'{index}.wav')})
                tasks.append((locale, surface, key, article, folder, out))
    if args.images_only:
        print(f'{len(tasks)} illustrated guides generated', flush=True)
        return
    jobs = work / 'speech.json'
    jobs.write_text(json.dumps(speech, ensure_ascii=False), encoding='utf-8-sig')
    ps = work / 'speech.ps1'
    ps.write_text('''param([string]$Jobs)
Add-Type -AssemblyName System.Speech
$voices = @{de='Microsoft Hedda Desktop'; en='Microsoft Zira Desktop'}
foreach ($job in (Get-Content -Raw -Encoding UTF8 -LiteralPath $Jobs | ConvertFrom-Json)) {
  if (Test-Path -LiteralPath $job.path) {continue}
  $speaker = New-Object System.Speech.Synthesis.SpeechSynthesizer
  try {$speaker.SelectVoice($voices[$job.locale]); $speaker.Rate=0
       $speaker.SetOutputToWaveFile($job.path); $speaker.Speak($job.text)}
  finally {$speaker.Dispose()}
}
''', encoding='utf-8-sig')
    print(f'Generating offline narration for {len(tasks)} guides', flush=True)
    subprocess.run(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', str(ps), '-Jobs', str(jobs)], check=True)
    import imageio_ffmpeg
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()

    def encode(task):
        locale, surface, key, article, folder, out = task
        concat = []; cues = ['WEBVTT', '']; elapsed = 0
        combined = folder / 'narration.wav'
        with wave.open(str(combined), 'wb') as target:
            for index, step in enumerate(article['steps']):
                with wave.open(str(folder / f'{index}.wav'), 'rb') as source:
                    if index == 0: target.setparams(source.getparams())
                    duration = source.getnframes() / source.getframerate()
                    target.writeframes(source.readframes(source.getnframes()))
                concat.extend([f"file '{index}.png'", f'duration {duration:.4f}'])
                cues.extend([f'{stamp(elapsed)} --> {stamp(elapsed + duration)}', step['title'] + '. ' + step['body'], ''])
                elapsed += duration
        concat.append(f"file '{len(article['steps']) - 1}.png'")
        (folder / 'slides.txt').write_text('\n'.join(concat), encoding='utf-8')
        (out / (key + '.vtt')).write_text('\n'.join(cues), encoding='utf-8')
        result = subprocess.run([ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', str(folder / 'slides.txt'), '-i', str(combined), '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'stillimage', '-r', '10', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '64k', '-t', str(elapsed), '-movflags', '+faststart', str(out / (key + '.mp4'))], capture_output=True)
        if result.returncode: raise RuntimeError(result.stderr.decode(errors='replace'))
        player = '<!doctype html><html lang="'+locale+'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+html.escape(article['title'])+'</title><style>body{font:18px system-ui;background:#f4f6fb;color:#18283f;max-width:1100px;margin:24px auto;padding:16px}video{width:100%}li{margin:16px 0}</style><h1>'+html.escape(article['title'])+'</h1><video controls playsinline preload="metadata" poster="'+key+'.png"><source src="'+key+'.mp4" type="video/mp4"><track kind="captions" src="'+key+'.vtt" srclang="'+locale+'" label="'+locale+'" default></video><ol>'+''.join('<li><strong>'+html.escape(x['title'])+'</strong><p>'+html.escape(x['body'])+'</p></li>' for x in article['steps'])+'</ol></html>'
        (out / (key + '.html')).write_text(player, encoding='utf-8')
        print(f'{locale}/{surface}/{key}: {elapsed:.1f}s', flush=True)
        metadata = {'source_sha256': hashlib.sha256(json.dumps(article, sort_keys=True, ensure_ascii=False).encode()).hexdigest(), 'locale': locale, 'surface': surface, 'topic': key, 'seconds': round(elapsed, 2), 'kind': 'narrated_step_slides', 'sha256': hashlib.sha256((out / (key + '.mp4')).read_bytes()).hexdigest()}
        (out / (key + '.source.json')).write_text(json.dumps(metadata, ensure_ascii=False), encoding='utf-8')
        return metadata

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        manifest = reused + list(pool.map(encode, tasks))
    (args.output / 'manifest.json').write_text(json.dumps({'format': 1, 'guides': manifest}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(f'Completed {len(manifest)} guides at {args.output}', flush=True)


if __name__ == '__main__': main()
