import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Job Queue',
  description: 'Database-backed job queue for PHP: leased claims with ownership tokens, per-job retry policies and a worker daemon on Doctrine DBAL',
  base: '/Job-Queue/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Job-Queue/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#f97316' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Job Queue' }],
    ['meta', { property: 'og:description', content: 'Database-backed job queue for PHP: leased claims with ownership tokens, per-job retry policies and a worker daemon on Doctrine DBAL' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'Features', link: '/features/jobs' },
      { text: 'API', link: '/api/job-queue' },
      { text: 'v2.0', link: 'https://github.com/SoloPHP/Job-Queue/releases' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/SoloPHP/Job-Queue' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/job-queue' },
          { text: 'SoloPHP', link: 'https://github.com/SoloPHP' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Upgrading from 1.x', link: 'https://github.com/SoloPHP/Job-Queue/blob/main/UPGRADING.md' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Jobs and Codecs', link: '/features/jobs' },
          { text: 'Enqueueing', link: '/features/enqueueing' },
          { text: 'Processing and Leases', link: '/features/processing' },
          { text: 'Retries and Deadlines', link: '/features/retries' },
          { text: 'Worker', link: '/features/worker' },
          { text: 'Maintenance and Monitoring', link: '/features/maintenance' },
          { text: 'Delivery and Errors', link: '/features/delivery' },
          { text: 'Schema and Databases', link: '/features/schema' }
        ]
      },
      {
        text: 'API Reference',
        items: [
          { text: 'JobQueue', link: '/api/job-queue' },
          { text: 'Value Objects', link: '/api/value-objects' },
          { text: 'Codec', link: '/api/codec' },
          { text: 'Storage', link: '/api/storage' },
          { text: 'Worker', link: '/api/worker' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Job-Queue' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: `Copyright © 2024-${new Date().getFullYear()} SoloPHP`
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Job-Queue/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
