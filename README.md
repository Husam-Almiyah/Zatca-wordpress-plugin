# ZATCA Wordpress Plugin

A comprehensive WordPress plugin that enables Saudi Arabian businesses to comply with ZATCA (Zakat, Tax and Customs Authority) e-invoicing requirements through WooCommerce integration.

## 🚀 Features

### ✅ ZATCA Compliance
- **Phase 1 Support**: QR Code generation for invoices
- **Phase 2 Support**: XML invoice generation and API submission
- **UBL 2.1 Compliant**: Generates standard-compliant XML invoices
- **Digital Signing**: Signs invoices with ZATCA certificates
- **API Integration**: Direct submission to ZATCA clearance/reporting APIs

### 💳 WooCommerce Integration
- **Automatic Generation**: Generates invoices when orders are processed/completed
- **Refund Support**: Automatically generates credit notes for refunded orders
- **Email Integration**: Adds QR codes to WooCommerce emails
- **Order Display**: Shows ZATCA information on order details pages
- **Admin Interface**: Complete admin panel for certificate and invoice management

### 🔧 Technical Features
- **Certificate Management**: CSR generation, certificate storage, and renewal
- **Error Handling**: Comprehensive error logging and debugging
- **REST API**: External API endpoints for integration
- **AJAX Support**: Admin interface with real-time operations
- **PDF Integration**: QR code embedding in PDF invoices
- **Multi-language**: Translation-ready with Arabic support

## 📋 Requirements
- **WordPress**: 5.0 or higher
- **WooCommerce**: 5.0 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **SSL Certificate**: Required for ZATCA API communication
- **ZATCA Account**: Valid ZATCA e-invoicing account

## 🛠️ Installation

> **📖 Detailed Installation Guide**: See [INSTALLATION.md](INSTALLATION.md) for comprehensive installation instructions and troubleshooting.

### Prerequisites
- **WordPress**: 5.0 or higher
- **WooCommerce**: 5.0 or higher  
- **PHP**: 7.4 or higher
- **Composer**: Required for dependency management (development only)
- **SSL Certificate**: Required for ZATCA API communication
- **ZATCA Account**: Valid ZATCA e-invoicing account

### Quick Installation (End Users)
1. **Download the release ZIP** from [GitHub Releases](https://github.com/Husam-Almiyah/Zatca-wordpress-plugin/releases)
2. **Go to WordPress Admin** → Plugins → Add New
3. **Click "Upload Plugin"** and select the ZIP file
4. **Click "Install Now"** and then "Activate Plugin"
5. **Configure** in WooCommerce → ZATCA

> **Note**: The release ZIP includes all dependencies and is ready to use without Composer.

## 📖 Usage

### Automatic Invoice Generation
The plugin automatically generates invoices when:
- Order status changes to "Processing"
- Order status changes to "Completed"
- Order status changes to "Refunded" (credit notes)

### Manual Operations
- **Generate Invoice**: Create invoice manually from order page
- **Submit to ZATCA**: Submit pending invoices to ZATCA
- **Regenerate QR**: Recreate QR codes if needed
- **View Status**: Check invoice submission status

### Credit Notes
- **Automatic**: Generated when order status changes to "Refunded"
- **Separate Storage**: Credit notes saved in separate database rows
- **ZATCA Submission**: Automatically submitted if auto-submit is enabled

## 🤝 Contributing

> **📖 Detailed Contributing Guide**: See [CONTRIBUTING.md](CONTRIBUTING.md) for comprehensive cONTRIBUTING instructions.

## 📞 Support

### Documentation
- [ZATCA Guidelines](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [WooCommerce Documentation](https://docs.woocommerce.com/)

### Community Support
- [GitHub Issues](https://github.com/Husam-Almiyah/Zatca-wordpress-plugin/issues)
- [WordPress Support Forums](https://wordpress.org/support/)
- [WooCommerce Community](https://community.woocommerce.com/)

### Commercial Support
For commercial support and custom development, please contact:
- **LinkedIn**: [husam-almiyah](https://www.linkedin.com/in/husam-almiyah/)
- **Email**: [Send an Email to husamalmiyah@gmail.com]()

---

**Note**: This plugin is designed for Saudi Arabian businesses to comply with ZATCA e-invoicing requirements. Ensure you have a valid ZATCA account and understand the compliance requirements before using this plugin in production.
