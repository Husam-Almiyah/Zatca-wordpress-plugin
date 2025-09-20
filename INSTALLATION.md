# Installation Guide

## Quick Start (End Users)

### Method 1: WordPress Admin Upload
1. **Download** the latest release ZIP from [GitHub Releases](https://github.com/Husam-Almiyah/Zatca-wordpress-plugin/releases)
2. **Login** to your WordPress admin dashboard
3. **Navigate** to Plugins → Add New
4. **Click** "Upload Plugin" button
5. **Select** the downloaded ZIP file
6. **Click** "Install Now" and then "Activate Plugin"
7. **Configure** by going to WooCommerce → ZATCA

### Method 2: Manual Upload
1. **Download** the release ZIP file
2. **Extract** the ZIP file to your computer
3. **Upload** the extracted folder to `/wp-content/plugins/` on your server
4. **Activate** the plugin through WordPress Admin → Plugins
5. **Configure** in WooCommerce → ZATCA

## Developer Installation

### Prerequisites
- **Composer**: Required for dependency management
- **Git**: For cloning the repository
- **WordPress**: 5.0 or higher
- **WooCommerce**: 5.0 or higher
- **PHP**: 7.4 or higher

### Installation Steps
1. **Clone the repository**:
   ```bash
   git clone https://github.com/Husam-Almiyah/Zatca-wordpress-plugin.git
   cd Zatca-wordpress-plugin
   ```

2. **Install dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Upload to WordPress**:
   - Zip the entire plugin folder
   - Upload via WordPress Admin → Plugins → Add New → Upload Plugin
   - Or extract to `/wp-content/plugins/zatca-wordpress-plugin/`

4. **Activate the plugin**:
   - Go to WordPress Admin → Plugins
   - Find "ZATCA Wordpress Plugin" and click "Activate"

## Configuration

### Settings
1. **Navigate** to WooCommerce → ZATCA
2. **Enable** the plugin
3. **Phase Selection**: Choose Phase 1 (QR) or Phase 2 (XML + API)
4. **Select** your ZATCA environment (Sandbox/Simulation/Production)
5. **Fill** your Company information
6. **Configure** auto-generation settings
7. **Fill** your Simulation/Production OTP
8. **Fill** your Certificate settings
9. **Save Changes**
    
### Setup
1. **Generate CSR**: Create a Certificate Signing Request.
2. **Request Compliance Certificate**: Request the Compliance Certificate from ZATCA.
3. **Run Compliance Checks**: Run ZATCA compliance checks based on your invoice types:
   * **B2C**: simplified, credit, debit notes
   * **B2B**: standard, credit, debit notes
   * **Both**: standard, credit, debit notes and simplified, credit, debit notes
   > ⚠️ It is important to run all required checks so ZATCA can capture them in their system and confirm your eligibility for the Production Certificate.
4. **Request Production Certificate**: Request the Production Certificate from ZATCA, which will be used for live invoice submission.

## Troubleshooting

### Common Issues

#### Plugin Activation Fails
**Problem**: Plugin fails to activate
**Solutions**:
- Check PHP version (requires 7.4+)
- Verify WooCommerce is installed and active
- Check WordPress error logs
- Ensure file permissions are correct

#### Composer Dependencies Missing
**Problem**: "Class not found" errors
**Solutions**:
- Run `composer install` in the plugin directory
- Check if `vendor/autoload.php` exists
- Verify Composer is installed on your server

#### API Connection Errors
**Problem**: Cannot connect to ZATCA API
**Solutions**:
- Check SSL certificate on your server
- Verify network connectivity
- Test with sandbox environment
- Check firewall settings

#### QR Code Not Generating
**Problem**: QR codes not appearing
**Solutions**:
- Enable debug mode in plugin settings
- Check order has valid billing information
- Verify invoice data is complete
- Check PHP GD extension is installed

### Debug Mode
Enable debug mode for detailed error information:
1. Go to WooCommerce → ZATCA → Settings
2. Enable "Debug Mode"
3. Check WordPress debug log (`/wp-content/debug.log`)

### Server Requirements
- **PHP**: 7.4 or higher
- **Extensions**: mbstring, xml, ctype, iconv, intl, pdo_mysql, dom, filter, gd, json
- **Memory**: 256MB minimum (512MB recommended)
- **SSL**: Required for ZATCA API communication

### File Permissions
Ensure proper file permissions:
```bash
# Plugin directory
chmod 755 /wp-content/plugins/Zatca-wordpress-plugin/
```
