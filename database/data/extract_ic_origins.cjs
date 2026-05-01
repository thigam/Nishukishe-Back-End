const fs = require('fs');

const data = JSON.parse(fs.readFileSync('/home/thiga/Documents/Projects/nishy_backup_19_05_2025/backend/database/data/routes.json', 'utf8'));

const icRoutesOrigins = new Set();

data.forEach(route => {
    const name = route.name;
    // Extract route number from {IC...}
    const match = name.match(/\{IC([^}]*)\}/);
    if (match) {
        // Extract origin: part after ": " and before " - "
        const parts = name.split(': ');
        if (parts.length > 1) {
            const desc = parts[1];
            const routeAndNum = desc.split(' {')[0];
            const startEnd = routeAndNum.split(' - ');
            if (startEnd.length > 0) {
                icRoutesOrigins.add(startEnd[0].trim());
            }
        }
    }
});

console.log(JSON.stringify(Array.from(icRoutesOrigins).sort(), null, 2));
