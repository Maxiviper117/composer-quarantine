---
layout: home

hero:
  name: Composer Quarantine
  text: Release-age quarantine for Composer dependencies
  tagline: Block versions that are too new until they age past your policy threshold.
  actions:
    - theme: brand
      text: Get Started
      link: /tutorials/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/Maxiviper117/composer-quarantine

features:
  - icon: 🛡️
    title: Age-based blocking
    details: Filter out package versions younger than your minimum age policy before Composer resolves them.
  - icon: 🧠
    title: Global by design
    details: Install once globally and protect every Composer project on the machine or CI runner.
  - icon: 🌐
    title: Packagist-backed
    details: Use authoritative Packagist metadata instead of local git timestamps or source metadata.
  - icon: ⚡
    title: Low overhead
    details: Cache metadata in-memory during a run and keep solver impact minimal.
---
