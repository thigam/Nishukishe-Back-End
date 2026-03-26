#!/usr/bin/env node
// database/data/extractRoutes.cjs

const fs   = require('fs');
const path = require('path');

// 1) Load your combined data module
//    Adjust this path if you moved your .cjs file elsewhere
const dataFile = path.join(__dirname, 'stopsData.cjs');
let allData;
try {
  allData = require(dataFile);
} catch (err) {
  console.error(`❌  Failed loading ${dataFile}:`, err.message);
  process.exit(1);
}

// 2) Grab the saccoRoutes array
const routes = allData.saccoRoutes;
if (!Array.isArray(routes)) {
  console.error(`❌  Expected an array at allData.saccoRoutes in ${dataFile}`);
  process.exit(1);
}

// 3) Dump it to JSON
const outPath = path.join(__dirname, 'routes.json');
fs.writeFileSync(outPath, JSON.stringify(routes, null, 2), 'utf8');
console.log(`✅  Extracted ${routes.length} routes → ${outPath}`);

