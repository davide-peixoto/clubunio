const fs = require('fs');
const path = require('path');
const sharp = require(process.env.SHARP_MODULE || 'sharp');

(async () => {
  for (const file of fs.readdirSync(__dirname).filter(f => f.endsWith('.svg'))) {
    const input = path.join(__dirname, file);
    const size = file.includes('app-icon') ? 1024 : file.includes('preview') ? 1400 : 1600;
    let output = sharp(input).resize({width:size});
    if (file.includes('app-icon')) output = output.removeAlpha();
    await output.png().toFile(input.replace('.svg', '.png'));
  }
  for (const size of [512, 192, 48, 32]) {
    await sharp(path.join(__dirname, 'clubunio-app-icon.svg')).resize(size,size).removeAlpha().png()
      .toFile(path.join(__dirname, `clubunio-app-icon-${size}.png`));
  }
  const logo = await sharp(path.join(__dirname, 'clubunio-logo.png')).raw().toBuffer({resolveWithObject:true});
  if (logo.info.channels !== 4 || logo.data[3] !== 0) throw Error('Expected transparent logo background');
  const icon = await sharp(path.join(__dirname, 'clubunio-app-icon.png')).metadata();
  if(icon.width !== 1024 || icon.height !== 1024 || icon.hasAlpha) throw Error('Icon dimensions or alpha incorrect');
  console.log('PNG exports verified: transparent logo, 1024px square app icon.');
})();
