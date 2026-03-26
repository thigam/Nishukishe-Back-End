#!/usr/bin/env node
// database/data/extractSaccos.cjs

const fs   = require('fs');
const path = require('path');

// 1) Load your combined data module
const dataFile = path.join(__dirname, 'stopsData.cjs');
let routesData;
try {
  routesData = require(dataFile);
} catch (err) {
  console.error(`❌  Failed to load ${dataFile}:`, err.message);
  process.exit(1);
}

// 2) Pull out the saccos array
const saccos = routesData.saccos;
if (!Array.isArray(saccos)) {
  console.error(`❌  Expected an array at routesData.saccos in ${dataFile}`);
  process.exit(1);
}

// 3) Write JSON to database/data/saccos.json
const outPath = path.join(__dirname, 'saccos.json');
fs.writeFileSync(outPath, JSON.stringify(saccos, null, 2), 'utf8');

console.log(`✅  Extracted ${saccos.length} saccos → ${outPath}`);

