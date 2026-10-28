// @ts-check

import {themes as prismThemes} from 'prism-react-renderer';

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'heic-ios18-fix',
  tagline: 'Restore iOS 18+ HEIC uploads in PHP Imagick',
  favicon: 'img/favicon.svg',

  future: {
    v4: true,
  },

  url: 'https://managingwp.github.io',
  baseUrl: '/heic-ios18-fix/',

  organizationName: 'managingwp',
  projectName: 'heic-ios18-fix',

  onBrokenLinks: 'throw',
  markdown: {
    hooks: {
      onBrokenMarkdownLinks: 'throw',
    },
  },

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          routeBasePath: '/',
          sidebarPath: './sidebars.js',
          editUrl:
            'https://github.com/managingwp/heic-ios18-fix/edit/main/docs/',
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
      }),
    ],
  ],

  themes: [
    [
      '@easyops-cn/docusaurus-search-local',
      ({
        hashed: true,
        indexBlog: false,
        language: ['en'],
        // Must mirror docs.routeBasePath above; the plugin default 'docs'
        // would index URLs that 404 on this project site.
        docsRouteBasePath: '/',
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      colorMode: {
        defaultMode: 'light',
        respectPrefersColorScheme: true,
      },
      navbar: {
        title: 'heic-ios18-fix',
        items: [
          {
            type: 'doc',
            docId: 'index',
            label: 'Docs',
            position: 'left',
          },
          {
            to: '/plugin-download',
            label: 'Plugin',
            position: 'right',
          },
          {
            href: 'https://github.com/managingwp/heic-ios18-fix',
            label: 'GitHub',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Docs',
            items: [
              {label: 'Getting started', to: '/getting-started'},
              {label: 'How it works', to: '/how-it-works'},
              {label: 'Commands', to: '/commands'},
            ],
          },
          {
            title: 'Platforms',
            items: [
              {label: 'GridPane', to: '/platforms/gridpane'},
              {label: 'RunCloud', to: '/platforms/runcloud'},
              {label: 'Ubuntu 26.04', to: '/platforms/ubuntu-26-04'},
            ],
          },
          {
            title: 'Project',
            items: [
              {
                label: 'GitHub',
                href: 'https://github.com/managingwp/heic-ios18-fix',
              },
              {label: 'Samples', to: '/samples'},
              {label: 'Troubleshooting', to: '/troubleshooting'},
            ],
          },
        ],
        copyright: `MIT © ${new Date().getFullYear()} Managing WP`,
      },
      docs: {
        sidebar: {
          hideable: true,
          autoCollapseCategories: true,
        },
      },
      prism: {
        theme: prismThemes.github,
        darkTheme: prismThemes.dracula,
      },
    }),
};

export default config;
