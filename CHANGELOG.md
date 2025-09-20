# Changelog

All notable changes to the ZATCA Wordpress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-09-19

### Added
- Initial release of ZATCA Wordpress plugin
- **Phase 1 Support**: QR Code generation for invoices
- **Phase 2 Support**: XML invoice generation and API submission
- **Certificate Management**: CSR generation, certificate storage, and renewal
- **WooCommerce Integration**: Automatic invoice generation on order status changes
- **Refund Support**: Automatic credit note generation for refunded orders
- **Admin Interface**: Complete admin panel for certificate and invoice management
- **AJAX Support**: Admin interface with real-time operations
- **PDF Integration**: QR code embedding in PDF invoices
- **Multi-language**: Translation-ready with Arabic support
- **Error Handling**: Comprehensive error logging and debugging
- **Security**: Certificate encryption and access control

### Features
- **Automatic Invoice Generation**: Triggers on order status changes (processing, completed, refunded)
- **Credit Note Support**: Separate storage for credit notes
- **ZATCA API Integration**: Direct submission to clearance/reporting APIs
- **UBL 2.1 Compliance**: Standard-compliant XML invoice generation
- **Digital Signing**: Invoice signing with ZATCA certificates
- **Zero Amount Validation**: Prevents processing of zero/negative amount invoices
- **Debug Mode**: Conditional error logging based on debug settings
- **Database Management**: Custom tables for invoice submissions and certificates

### Technical Implementation
- **Database Tables**: 
  - `wp_zatca_invoice_submissions`: Stores invoice and credit note data
  - `wp_zatca_certificates`: Stores ZATCA certificates
- **File Structure**: Organized includes with separate classes for different functionalities
- **WordPress Standards**: Follows WordPress coding standards and best practices
- **Error Handling**: Comprehensive error handling with user-friendly messages
- **Security**: Nonce verification, input validation, and access control

### Compatibility
- **WordPress**: 5.0+
- **WooCommerce**: 5.0+
- **PHP**: 7.4+
- **MySQL**: 5.7+

### Documentation
- **README.md**: Comprehensive documentation with installation and usage instructions
- **LICENSE**: GPL v3 license for open source distribution
- **CHANGELOG**: This file for tracking version changes

### Localization
- **English**: Default language
- **Arabic**: Full Arabic translation support
- **Translation Files**: POT, PO, and MO files included

## [Unreleased]

### Planned Features
- Enhanced reporting and analytics
- Bulk invoice operations
- Advanced certificate management
- Performance optimizations

### Known Issues
- None currently identified

---

## Version History

### Version 1.0.0
- Initial stable release
- Full ZATCA compliance implementation
- Complete WooCommerce integration
- Production-ready
