const TAG_PATTERN = /^heic-monitor-v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;
const PER_PAGE = 100;
const MAX_PAGES = 5;

export const REQUIRED_ASSETS = ["heic-fix-monitor.zip", "heic-fix-monitor.zip.sha256"];
export const RELEASES_API =
  "https://api.github.com/repos/managingwp/heic-ios18-fix/releases";
export const RELEASES_PAGE =
  "https://github.com/managingwp/heic-ios18-fix/releases";

export function parsePluginTag(tag) {
  const match = TAG_PATTERN.exec(String(tag ?? ""));
  if (!match) {
    return null;
  }

  return {
    major: Number(match[1]),
    minor: Number(match[2]),
    patch: Number(match[3]),
    version: `${match[1]}.${match[2]}.${match[3]}`,
  };
}

export function comparePluginVersions(left, right) {
  if (left.major !== right.major) {
    return left.major - right.major;
  }
  if (left.minor !== right.minor) {
    return left.minor - right.minor;
  }
  return left.patch - right.patch;
}

function hasRequiredAssets(release) {
  if (!Array.isArray(release.assets)) {
    return false;
  }

  return REQUIRED_ASSETS.every((name) => {
    const asset = release.assets.find((candidate) => candidate?.name === name);
    return typeof asset?.browser_download_url === "string" &&
      asset.browser_download_url.startsWith("https://");
  });
}

export function selectLatestPluginRelease(releases) {
  if (!Array.isArray(releases) || releases.length === 0) {
    return null;
  }

  const candidates = releases
    .map((release) => {
      if (!release || release.draft === true || release.prerelease === true) {
        return null;
      }
      const parsed = parsePluginTag(release.tag_name);
      if (!parsed || !hasRequiredAssets(release)) {
        return null;
      }
      return { release, parsed };
    })
    .filter(Boolean)
    .sort((left, right) => {
      const comparison = comparePluginVersions(right.parsed, left.parsed);
      if (comparison !== 0) {
        return comparison;
      }
      return String(right.release.tag_name).localeCompare(String(left.release.tag_name));
    });

  return candidates.length > 0 ? candidates[0].release : null;
}

export function pluginAssetUrl(release, name) {
  if (!release || !Array.isArray(release.assets)) {
    return null;
  }

  const asset = release.assets.find((candidate) => candidate?.name === name);
  return typeof asset?.browser_download_url === "string"
    ? asset.browser_download_url
    : null;
}

export function pluginVersion(release) {
  const parsed = parsePluginTag(release?.tag_name);
  return parsed ? parsed.version : null;
}

export async function fetchLatestPluginRelease(options = {}) {
  const { fetchImpl = globalThis.fetch, signal } = options;
  if (typeof fetchImpl !== "function") {
    throw new TypeError("A fetch implementation is required");
  }

  const collected = [];

  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const response = await fetchImpl(
      `${RELEASES_API}?per_page=${PER_PAGE}&page=${page}`,
      {
        headers: { Accept: "application/vnd.github+json" },
        signal,
      },
    );

    if (!response || response.ok !== true) {
      throw new Error(
        `GitHub API request failed (${response?.status ?? "unknown"})`,
      );
    }

    const batch = await response.json();
    if (!Array.isArray(batch)) {
      throw new Error("GitHub API returned an unexpected payload");
    }

    collected.push(...batch);
    if (batch.length < PER_PAGE) {
      break;
    }
  }

  return selectLatestPluginRelease(collected);
}
