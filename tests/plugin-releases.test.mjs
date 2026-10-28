import assert from 'node:assert/strict';
import test from 'node:test';
import {
  comparePluginVersions,
  fetchLatestPluginRelease,
  parsePluginTag,
  pluginAssetUrl,
  pluginVersion,
  REQUIRED_ASSETS,
  selectLatestPluginRelease,
} from '../src/lib/pluginReleases.mjs';

function assets(tag) {
  return REQUIRED_ASSETS.map((name) => ({
    name,
    browser_download_url: `https://github.com/managingwp/heic-ios18-fix/releases/download/${tag}/${name}`,
  }));
}

function release(tag, overrides = {}) {
  return {
    tag_name: tag,
    draft: false,
    prerelease: false,
    published_at: '2026-10-28T12:00:00Z',
    html_url: `https://github.com/managingwp/heic-ios18-fix/releases/tag/${tag}`,
    assets: assets(tag),
    ...overrides,
  };
}

test('parses strict plugin tags', () => {
  assert.deepEqual(parsePluginTag('heic-monitor-v0.1.10'), {
    major: 0,
    minor: 1,
    patch: 10,
    version: '0.1.10',
  });
  assert.equal(parsePluginTag('heic-monitor-v0.1'), null);
  assert.equal(parsePluginTag('heic-monitor-v01.0.0'), null);
  assert.equal(parsePluginTag('v0.1.0'), null);
  assert.equal(parsePluginTag('heic-monitor-v0.1.0-rc1'), null);
  assert.equal(parsePluginTag(null), null);
});

test('compares versions numerically', () => {
  assert.ok(
    comparePluginVersions(
      parsePluginTag('heic-monitor-v0.1.10'),
      parsePluginTag('heic-monitor-v0.1.9'),
    ) > 0,
  );
  assert.equal(
    comparePluginVersions(
      parsePluginTag('heic-monitor-v0.1.1'),
      parsePluginTag('heic-monitor-v0.1.1'),
    ),
    0,
  );
});

test('selects the highest stable plugin release', () => {
  const selected = selectLatestPluginRelease([
    release('heic-monitor-v0.1.9'),
    release('heic-monitor-v0.1.10'),
    release('heic-monitor-v0.1.2'),
  ]);

  assert.equal(selected.tag_name, 'heic-monitor-v0.1.10');
  assert.equal(pluginVersion(selected), '0.1.10');
});

test('ignores drafts, prereleases, installer releases and malformed tags', () => {
  const selected = selectLatestPluginRelease([
    release('heic-monitor-v9.0.0', {draft: true}),
    release('heic-monitor-v8.0.0', {prerelease: true}),
    release('v7.0.0'),
    release('heic-monitor-v6.0'),
    release('heic-monitor-v5.0.0', {assets: []}),
    release('heic-monitor-v4.0.0', {
      assets: [
        {
          name: 'heic-fix-monitor.zip',
          browser_download_url:
            'https://github.com/managingwp/heic-ios18-fix/releases/download/heic-monitor-v4.0.0/heic-fix-monitor.zip',
        },
      ],
    }),
    release('heic-monitor-v0.2.0'),
  ]);

  assert.equal(selected.tag_name, 'heic-monitor-v0.2.0');
});

test('returns null when no release is installable', () => {
  assert.equal(selectLatestPluginRelease([]), null);
  assert.equal(selectLatestPluginRelease(null), null);
  assert.equal(
    selectLatestPluginRelease([release('heic-monitor-v1.0.0', {draft: true})]),
    null,
  );
});

test('exposes the ZIP and checksum URLs only from required assets', () => {
  const selected = release('heic-monitor-v0.1.0');
  assert.match(pluginAssetUrl(selected, 'heic-fix-monitor.zip'), /heic-fix-monitor\.zip$/);
  assert.match(
    pluginAssetUrl(selected, 'heic-fix-monitor.zip.sha256'),
    /heic-fix-monitor\.zip\.sha256$/,
  );
  assert.equal(pluginAssetUrl(selected, 'other.zip'), null);
  assert.equal(pluginAssetUrl(null, 'heic-fix-monitor.zip'), null);
});

test('paginates until a short page and selects from later pages', async () => {
  const firstPage = Array.from({length: 100}, (_, index) =>
    release(`installer-v1.0.${index}`, {assets: []}),
  );
  const secondPage = [release('heic-monitor-v0.3.0')];
  const requested = [];

  const fetchImpl = async (url) => {
    requested.push(url);
    const page = Number(new URL(url).searchParams.get('page'));
    return {
      ok: true,
      status: 200,
      json: async () => (page === 1 ? firstPage : secondPage),
    };
  };

  const selected = await fetchLatestPluginRelease({fetchImpl});
  assert.equal(selected.tag_name, 'heic-monitor-v0.3.0');
  assert.equal(requested.length, 2);
  assert.match(requested[0], /per_page=100&page=1/);
  assert.match(requested[1], /page=2/);
});

test('rejects API failures and unexpected payloads', async () => {
  await assert.rejects(
    fetchLatestPluginRelease({
      fetchImpl: async () => ({ok: false, status: 403, json: async () => ({})}),
    }),
    /GitHub API request failed \(403\)/,
  );

  await assert.rejects(
    fetchLatestPluginRelease({
      fetchImpl: async () => ({ok: true, status: 200, json: async () => ({})}),
    }),
    /unexpected payload/,
  );
});

test('requires a fetch implementation', async () => {
  await assert.rejects(fetchLatestPluginRelease({fetchImpl: null}), TypeError);
});
