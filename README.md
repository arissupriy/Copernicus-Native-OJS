# Copernicus Native OJS Plugin (XML Export)

Developed by [Aris Supriyanto](https://github.com/arissupriy)

## Description
This plugin is an Import/Export plugin for Open Journal Systems (OJS) 3.4 and 3.5. It generates standard Index Copernicus XML directly via the OJS API. 

## Features
- **Native OJS Integration:** Fully integrated with OJS 3.4/3.5 routing and dispatching system.
- **XML Generation:** Builds well-formed Index Copernicus XML files from published journal issues.
- **Backend UI:** Provides a clean interface within the OJS admin panel to select and export issues.
- **API Enabled:** Exposes an API endpoint (`/index.php/api/v1/copernicus`) to retrieve XML dynamically.

## Installation
1. Upload this plugin to the `plugins/importexport/copernicusNative` directory of your OJS installation.
2. Go to your OJS Dashboard as an Administrator.
3. Navigate to **Website Settings > Plugins > Installed Plugins** and enable the plugin.
4. Go to **Tools > Import/Export** to use the plugin interface.
