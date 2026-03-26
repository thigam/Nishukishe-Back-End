#!/usr/bin/env node
// database/data/extractStops.cjs

const fs   = require('fs');
const path = require('path');

// Both files now live in database/data/
const dataDir = __dirname; 
const dataFile = path.join(dataDir, 'stopsData.cjs');

let stopsRoutesData;
try {
  stopsRoutesData = require(dataFile);
} catch (err) {
  console.error(`❌  Failed to load ${dataFile}:`, err.message);
  process.exit(1);
}

// Ensure the export is there
if (!Array.isArray(stopsRoutesData.stops)) {
  console.error(`❌  ${dataFile} did not export an array named "stops"`);
  process.exit(1);
}
const stops = stopsRoutesData.stops;

// Write out JSON in the same folder
const outPath = path.join(dataDir, 'stops.json');
fs.writeFileSync(outPath, JSON.stringify(stops, null, 2), 'utf8');
console.log(`✅  Extracted ${stops.length} stops → ${outPath}`);

