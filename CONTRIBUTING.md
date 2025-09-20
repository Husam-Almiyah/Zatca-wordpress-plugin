# Contributing to ZATCA Wordpress

Thank you for your interest in contributing to the ZATCA Wordpress plugin! This document provides guidelines and information for contributors.

## 🤝 How to Contribute

### Reporting Issues

Before creating a new issue, please:

1. **Search existing issues** to see if your problem has already been reported
2. **Check the documentation** to ensure the issue isn't already covered
3. **Test with a clean installation** to rule out conflicts with other plugins

When reporting an issue, please include:

- **WordPress version**
- **WooCommerce version**
- **Plugin version**
- **PHP version**
- **Detailed description** of the problem
- **Steps to reproduce** the issue
- **Error logs** (if applicable)
- **Screenshots** (if helpful)

### Feature Requests

We welcome feature requests! Please:

1. **Describe the feature** clearly and concisely
2. **Explain the use case** and why it's needed
3. **Provide examples** of how it should work
4. **Consider alternatives** and discuss trade-offs

### Pull Requests

We love pull requests! Here's how to contribute:

#### Before You Start

1. **Fork the repository**
2. **Create a feature branch**: `git checkout -b feature/your-feature-name`
3. **Set up your development environment**
4. **Read the coding standards** below

#### Development Setup

1. **Clone your fork**:
   ```bash
   git clone https://github.com/your-username/Zatca-wordpress-plugin.git
   cd Zatca-wordpress-plugin
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Set up WordPress** for testing:
   - Install WordPress locally
   - Install WooCommerce
   - Install the plugin in development mode

#### Making Changes

1. **Follow WordPress coding standards**:
   - Use WordPress coding standards
   - Follow PSR-12 for PHP
   - Use proper PHPDoc comments
   - Keep functions small and focused

2. **Update documentation**:
   - Update README.md if needed
   - Add inline code comments
   - Update CHANGELOG.md for new features

3. **Test thoroughly**:
   - Test on different WordPress/WooCommerce versions
   - Test with different PHP versions
   - Test edge cases and error conditions
   - Test with different ZATCA environments

#### Submitting Your PR

1. **Commit your changes**:
   ```bash
   git add .
   git commit -m "Add feature: brief description"
   ```

2. **Push to your fork**:
   ```bash
   git push origin feature/your-feature-name
   ```

3. **Create a pull request**:
   - Use a clear, descriptive title
   - Describe the changes in detail
   - Link to any related issues
   - Include screenshots if UI changes

## 📋 Coding Standards

### PHP Standards

- **WordPress Coding Standards**: Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- **PSR-12**: Follow PSR-12 for general PHP code
- **PHPDoc**: Use proper PHPDoc comments for all functions and classes
- **Naming**: Use descriptive names for variables, functions, and classes

### File Structure

```
zatca-wordpress-plugin/
├── includes/
│   ├── class-zatca-phase1.php
│   ├── class-zatca-phase2.php
│   ├── class-zatca-woocommerce-integration.php
│   ├── class-zatca-settings.php
│   ├── class-zatca-certificate-manager.php
│   ├── Admin/
│   │   └── class-zatca-admin-settings.php
│   ├── API/
│   │   └── class-zatca-api.php
│   ├── Helpers/
│   │   ├── Certificate.php
│   │   └── InvoiceSignatureBuilder.php
│   └── Signature/
│       └── InvoiceSigner.php
├── languages/
├── assets/
├── README.md
├── LICENSE
├── CHANGELOG.md
└── zatca-e-invoicing.php
```

### Code Examples

#### Function Documentation
```php
/**
 * Generate QR code for an order.
 *
 * @param int $order_id The WooCommerce order ID.
 * @param array $options Optional. Additional options for QR generation.
 * @param string $invoice_type Optional. Type of invoice (simplified, standard).
 * @return array|WP_Error QR code data on success, WP_Error on failure.
 */
public function generate_qr_code($order_id, $options = array(), $invoice_type = 'simplified') {
    // Function implementation
}
```

#### Error Handling
```php
if (!$order) {
    return new WP_Error('invalid_order', __('Invalid order ID.', 'zatca-invoicing'));
}
```

#### Security
```php
// Always verify nonces
check_ajax_referer('zatca_admin_nonce', 'nonce');

// Sanitize inputs
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

// Check permissions
if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
}
```

## 🧪 Testing

### Manual Testing

Test the following scenarios:

1. **Basic Functionality**:
   - Plugin activation/deactivation
   - Settings page functionality
   - Certificate management
   - Invoice generation
   - QR code generation
   - API submission

2. **WooCommerce Integration**:
   - Order status changes
   - Email integration
   - Admin order page
   - Customer order page

3. **Error Handling**:
   - Invalid certificates
   - API connection failures
   - Invalid order data
   - Permission errors

4. **Edge Cases**:
   - Zero amount orders
   - Refunded orders
   - Large orders
   - Special characters in data

## 📝 Documentation

### Code Documentation

- **PHPDoc**: All functions and classes must have PHPDoc comments
- **Inline Comments**: Add comments for complex logic
- **README**: Keep README.md updated with new features
- **CHANGELOG**: Document all changes in CHANGELOG.md

### User Documentation

- **Installation Guide**: Clear installation instructions
- **Configuration Guide**: Step-by-step configuration
- **Troubleshooting**: Common issues and solutions
- **API Documentation**: REST API endpoint documentation

## 🔒 Security

### Security Guidelines

1. **Input Validation**: Always validate and sanitize user inputs
2. **Output Escaping**: Escape all output to prevent XSS
3. **Nonce Verification**: Use nonces for all forms and AJAX requests
4. **Permission Checks**: Verify user permissions before actions
5. **SQL Injection**: Use prepared statements for database queries
6. **File Uploads**: Validate file uploads and restrict file types

### Reporting Security Issues

If you discover a security vulnerability, please:

1. **Do not disclose it publicly**
2. **Email us directly** at security@your-domain.com
3. **Provide detailed information** about the vulnerability
4. **Wait for our response** before any public disclosure

## 🌐 Localization

### Translation Guidelines

1. **Use Text Domain**: Always use 'zatca-invoicing' text domain
2. **Context**: Provide context for translators when needed
3. **Plurals**: Handle plural forms correctly
4. **Variables**: Use placeholders for dynamic content

### Example
```php
// Good
echo sprintf(__('Invoice generated for order #%d', 'zatca-invoicing'), $order_id);

// Bad
echo 'Invoice generated for order #' . $order_id;
```

## 📞 Getting Help

### Community Support

- **GitHub Issues**: For bug reports and feature requests
- **WordPress Support Forums**: For general WordPress questions
- **WooCommerce Community**: For WooCommerce-specific questions

### Developer Resources

- **WordPress Developer Handbook**: https://developer.wordpress.org/
- **WooCommerce Developer Documentation**: https://docs.woocommerce.com/
- **ZATCA Documentation**: https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx
- **Repository**: https://github.com/Husam-Almiyah/Zatca-wordpress-plugin

## 🙏 Recognition

Contributors will be recognized in:

- **README.md**: List of contributors
- **CHANGELOG.md**: Credit for significant contributions
- **Plugin Header**: Credit for major contributions

## 📄 License

By contributing to this project, you agree that your contributions will be licensed under the same license as the project (GPL v3 or later).

---

Thank you for contributing to the ZATCA Wordpress plugin! Your contributions help make this plugin better for the entire WordPress and WooCommerce community.
