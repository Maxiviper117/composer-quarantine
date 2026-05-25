import { defineConfig } from 'vitepress'

export default defineConfig({
  srcDir: 'docs',
  base: '/composer-quarantine/',

  title: 'Composer Quarantine',
  description: 'Global release-age quarantine for Composer package versions',

  themeConfig: {
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Getting started', link: '/tutorials/getting-started' },
        ],
      },
      {
        text: 'How-to Guides',
        items: [
          { text: 'Configure policy', link: '/how-to/configure-policy' },
        ],
      },
      {
        text: 'Explanation',
        items: [
          { text: 'Mental model', link: '/explanation/mental-model' },
          { text: 'Caching', link: '/explanation/caching' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Configuration', link: '/reference/configuration' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/Maxiviper117/composer-quarantine' },
    ],

    search: {
      provider: 'local',
    },
  },
})
