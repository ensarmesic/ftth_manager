import { cpSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const output = join(root, 'public', 'vendor');

mkdirSync(join(output, 'leaflet', 'images'), { recursive: true });
mkdirSync(join(output, 'proj4'), { recursive: true });

cpSync(join(root, 'node_modules', 'leaflet', 'dist', 'leaflet.js'), join(output, 'leaflet', 'leaflet.js'));
cpSync(join(root, 'node_modules', 'leaflet', 'dist', 'leaflet.css'), join(output, 'leaflet', 'leaflet.css'));
cpSync(join(root, 'node_modules', 'leaflet', 'dist', 'images'), join(output, 'leaflet', 'images'), { recursive: true });
cpSync(join(root, 'node_modules', 'proj4', 'dist', 'proj4.js'), join(output, 'proj4', 'proj4.js'));

process.stdout.write('Map vendor datoteke su sinhronizovane.\n');
