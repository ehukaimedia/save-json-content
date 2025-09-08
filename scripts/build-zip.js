#!/usr/bin/env node
/*
 * Zips the plugin into ./upload/{slug}-v{version}.zip
 * Usage: npm run build
 */
const { execSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = process.cwd();
const slug = path.basename(root); // e.g., save-json-content
const uploadDir = path.join(root, 'upload');
const buildDir = path.join(root, 'build');
const stageDir = path.join(buildDir, slug);

function readVersion() {
  const pluginFile = path.join(root, 'save-json-content.php');
  let version = '0.0.0';
  try {
    const txt = fs.readFileSync(pluginFile, 'utf8');
    // Try header first: Version: x.y.z
    const m1 = txt.match(/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi);
    if (m1 && m1[1]) return m1[1].trim();
    // Fallback to constant
    const m2 = txt.match(/define\(\s*['\"]SAVEJSON_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/);
    if (m2 && m2[1]) return m2[1].trim();
  } catch (_) {}
  return version;
}

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function hasBin(cmd) {
  const res = spawnSync(process.platform === 'win32' ? 'where' : 'which', [cmd], { stdio: 'ignore' });
  return res.status === 0;
}

function safeExec(cmd, opts = {}) {
  try {
    execSync(cmd, { stdio: 'inherit', ...opts });
    return true;
  } catch (e) {
    return false;
  }
}

function main() {
  const version = readVersion();
  const zipName = `${slug}-v${version}.zip`;
  const zipPath = path.join(uploadDir, zipName);

  console.log(`[build] Slug: ${slug}`);
  console.log(`[build] Version: ${version}`);
  ensureDir(uploadDir);
  ensureDir(buildDir);

  if (fs.existsSync(stageDir)) {
    console.log('[build] Cleaning previous build staging...');
    fs.rmSync(stageDir, { recursive: true, force: true });
  }

  // Copy files into staging using rsync or fallback to cp -R
  console.log('[build] Staging files...');
  const excludes = [
    '.git', '.github', 'node_modules', 'build', 'upload', '.DS_Store', '*.zip', '.playwright', '.context'
  ].map(e => `--exclude='${e}'`).join(' ');

  let staged = false;
  if (hasBin('rsync')) {
    staged = safeExec(`rsync -a ${excludes} ./ "${stageDir}/"`);
  }
  if (!staged) {
    // Fallback: shell copy + manual excludes (best-effort)
    ensureDir(stageDir);
    const copyCmd = `tar -cf - --exclude='.git' --exclude='.github' --exclude='node_modules' --exclude='build' --exclude='upload' --exclude='.DS_Store' --exclude='*.zip' --exclude='.playwright' --exclude='.context' . | tar -xf - -C "${stageDir}"`;
    staged = safeExec(copyCmd);
  }
  if (!staged) {
    console.error('[build] Failed to stage files. Aborting.');
    process.exit(1);
  }

  // Create zip using zip or ditto as fallback
  console.log('[build] Creating zip...');
  let zipped = false;
  if (hasBin('zip')) {
    zipped = safeExec(`(cd "${buildDir}" && zip -rq "${zipPath}" "${slug}")`);
  }
  if (!zipped && hasBin('ditto')) {
    zipped = safeExec(`(cd "${buildDir}" && ditto -c -k --sequesterRsrc --keepParent "${slug}" "${zipPath}")`);
  }
  if (!zipped) {
    console.error('[build] Neither zip nor ditto succeeded. Aborting.');
    process.exit(1);
  }

  console.log(`[build] Done → ${zipPath}`);
}

main();

