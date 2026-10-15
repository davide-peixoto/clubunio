// Publish only runtime identity assets, not the design source files.
const fs = require('fs');
const path = require('path');
const sharp = require(process.env.SHARP_MODULE || 'sharp');
const target = path.resolve(__dirname, '../../../public/brand/blue-v1');
(async () => {
  fs.mkdirSync(target, {recursive:true});
  for (const name of ['clubunio-logo-horizontal.svg','clubunio-logo-horizontal-white.svg','clubunio-symbol.svg','clubunio-app-icon.svg']) {
    fs.copyFileSync(path.join(__dirname,name),path.join(target,name));
  }
  await sharp(path.join(__dirname,'clubunio-app-icon.svg')).resize(180,180).removeAlpha().png().toFile(path.join(target,'apple-touch-icon.png'));
  const png = await sharp(path.join(__dirname,'clubunio-app-icon.svg')).resize(32,32).removeAlpha().png().toBuffer();
  const header = Buffer.alloc(22);
  header.writeUInt16LE(1,2); header.writeUInt16LE(1,4);
  header[6]=32; header[7]=32;
  header.writeUInt16LE(1,10); header.writeUInt16LE(32,12);
  header.writeUInt32LE(png.length,14); header.writeUInt32LE(22,18);
  fs.writeFileSync(path.resolve(target,'../../favicon.ico'),Buffer.concat([header,png]));
  console.log('Published logos, touch icon and ICO favicon.');
})();
