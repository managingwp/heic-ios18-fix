/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  docs: [
    'index',
    'getting-started',
    'commands',
    'how-it-works',
    {
      type: 'category',
      label: 'Platforms',
      collapsed: false,
      items: [
        'platforms/gridpane',
        'platforms/runcloud',
        'platforms/ubuntu-26-04',
      ],
    },
    'troubleshooting',
    'samples',
    'monitoring',
    'plugin-download',
    'plugin-releases',
    'testing',
    'alternatives',
    'references',
  ],
};

export default sidebars;
