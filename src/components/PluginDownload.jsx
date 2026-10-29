import React, {useEffect, useState} from 'react';
import {
  fetchLatestPluginRelease,
  pluginAssetUrl,
  pluginVersion,
  RELEASES_PAGE,
} from '../lib/pluginReleases.mjs';

const CHECKSUM = 'heic-fix-monitor.zip.sha256';
const ZIP = 'heic-fix-monitor.zip';

function formatDate(value) {
  if (!value) {
    return null;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return date.toISOString().slice(0, 10);
}

export default function PluginDownload() {
  const [state, setState] = useState({status: 'loading'});

  useEffect(() => {
    const controller = new AbortController();

    fetchLatestPluginRelease({signal: controller.signal})
      .then((release) => {
        setState(release ? {status: 'ready', release} : {status: 'empty'});
      })
      .catch((error) => {
        if (error?.name !== 'AbortError') {
          setState({status: 'error'});
        }
      });

    return () => controller.abort();
  }, []);

  return (
    <>
      <p>
              Read-only monitoring and transition alerts for the iOS 18
              HEIC/Imagick repair. The ZIP is a standard WordPress plugin
              package with one top-level <code>heic-fix-monitor/</code>
              directory.
            </p>

            {state.status === 'loading' && (
              <p role="status">Checking the latest plugin release…</p>
            )}

            {state.status === 'error' && (
              <div className="alert alert--warning" role="alert">
                <p>
                  The GitHub release list could not be loaded. Browse the
                  releases directly and choose the newest tag beginning with
                  <code> heic-monitor-v</code>.
                </p>
                <p>
                  <a href={RELEASES_PAGE}>Open GitHub Releases</a>
                </p>
              </div>
            )}

            {state.status === 'empty' && (
              <div className="alert alert--info" role="status">
                <p>No published plugin release is available yet.</p>
                <p>
                  <a href={RELEASES_PAGE}>Check GitHub Releases</a> for the
                  first plugin release.
                </p>
              </div>
            )}

            {state.status === 'ready' && (
              <>
                <h2>Version {pluginVersion(state.release)}</h2>
                {formatDate(state.release.published_at) && (
                  <p>Published {formatDate(state.release.published_at)}.</p>
                )}

                <p>
                  <a
                    className="button button--primary button--lg"
                    href={pluginAssetUrl(state.release, ZIP)}>
                    Download heic-fix-monitor.zip
                  </a>
                </p>

                <p>
                  <a href={pluginAssetUrl(state.release, CHECKSUM)}>
                    Download the SHA-256 checksum
                  </a>
                  {state.release.html_url && (
                    <>
                      {' · '}
                      <a href={state.release.html_url}>Release notes</a>
                    </>
                  )}
                </p>

                <h2>Install</h2>
                <ol>
                  <li>Download <code>heic-fix-monitor.zip</code>.</li>
                  <li>
                    In WordPress, open <strong>Plugins → Add New → Upload
                    Plugin</strong>.
                  </li>
                  <li>
                    Upload the ZIP, activate it, then open <strong>Settings →
                    HEIC Fix Monitor</strong>.
                  </li>
                </ol>

                <h2>Verify the download</h2>
                <pre>
                  <code>sha256sum -c heic-fix-monitor.zip.sha256</code>
                </pre>
                <p>Expected: <code>heic-fix-monitor.zip: OK</code></p>

                <h2>Compatibility</h2>
                <ul>
                  <li>WordPress 6.4 or newer</li>
                  <li>PHP 8.0 or newer</li>
                  <li>No Composer dependencies at runtime</li>
                </ul>

                <p>
                  This is a manual download. The plugin does not add a
                  WordPress dashboard update channel.
                </p>
              </>
            )}

      <p>
        <a href={RELEASES_PAGE}>All HEIC Fix Monitor releases</a>
      </p>
    </>
  );
}
