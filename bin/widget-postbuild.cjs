#!/usr/bin/env node

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const widgetJs = path.join(root, 'public/widget/widget.js');
const versionEnv = (() => {
    try {
        const env = fs.readFileSync(path.join(root, '.env'), 'utf8');
        const match = env.match(/^VERSION=(.*)$/m);

        return match ? match[1].trim() : 'dev';
    } catch {
        return 'dev';
    }
})();

if (!fs.existsSync(widgetJs)) {
    console.error('widget-postbuild: widget.js not found at', widgetJs);
    process.exit(1);
}

const source = fs.readFileSync(widgetJs);
const hash = crypto.createHash('sha256').update(source).digest('hex').slice(0, 12);
const hashedName = `widget.${hash}.js`;
const hashedPath = path.join(root, 'public/widget', hashedName);

fs.writeFileSync(hashedPath, source);

const manifest = {
    version: versionEnv,
    hash,
    file: hashedName,
    url: `/widget/${hashedName}`,
    generated_at: new Date().toISOString(),
};

fs.writeFileSync(
    path.join(root, 'public/widget/manifest.json'),
    JSON.stringify(manifest, null, 2) + '\n',
);

// Remove the unhashed widget.js from disk so Apache / Nginx don't
// serve it directly with default (long-lived) cache headers. The
// Laravel route at /widget/widget.js (WidgetBundleController) then
// streams the hashed file from manifest.json with `Cache-Control:
// no-cache, must-revalidate`. Buyer reported 2026-05-21: re-deploy
// did nothing because the proxy + browser cache held the stale
// bundle for days. Routing through PHP forces the freshness check
// without requiring any web-server config change.
try {
    fs.unlinkSync(widgetJs);
    console.log('widget-postbuild: removed unhashed widget.js (served via Laravel route)');
} catch {
    // best-effort; missing file is fine
}

console.log(`widget-postbuild: emitted ${hashedName} (${(source.length / 1024).toFixed(1)} KB) + manifest.json`);
