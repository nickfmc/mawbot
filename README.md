# WP GPT Chatbot - Setup Instructions

## Overview
WP GPT Chatbot is a WordPress plugin that allows you to integrate a custom ChatGPT-powered chatbot into your WordPress websites. The chatbot is self-hosted and can be trained with your own material to provide accurate and relevant responses.

## Requirements
- WordPress 5.0 or higher
- PHP 7.2 or higher
- OpenAI API key (https://platform.openai.com/account/api-keys)
- jQuery (included in WordPress)

## Installation

### Manual Installation
1. Download the plugin zip file or copy the `wp-gpt-chatbot` folder to your WordPress installation
2. Go to your WordPress admin panel > Plugins > Add New > Upload Plugin
3. Select the downloaded zip file and click "Install Now"
4. Activate the plugin after installation

### FTP Installation
1. Extract the zip file
2. Upload the `wp-gpt-chatbot` folder to your `/wp-content/plugins/` directory
3. Go to your WordPress admin panel > Plugins
4. Find "WP GPT Chatbot" and click "Activate"

## Configuration

1. After activation, go to WordPress admin panel > GPT Chatbot
2. Enter your OpenAI API key in the API Settings section
3. Choose the OpenAI model (GPT-3.5 Turbo is recommended for a balance of cost and performance)
4. Add your custom training material in the System Prompt field
5. Customize the appearance settings:
   - Bot Name: The name displayed in the chatbot header
   - Welcome Message: The initial message shown when the chatbot is opened
   - Primary Color: The main color for the chatbot button and header
   - Secondary Color: The text color for the chatbot header
   - Widget Position: Choose between bottom-right or bottom-left

6. Click "Save Changes" to apply your settings

## Training Your Chatbot

The System Prompt field is where you can provide instructions and training material for your chatbot. This is what guides the chatbot's responses. Here are some examples of what you might include:

### Example 1: Company Information
```
You are a helpful assistant for XYZ Company. You specialize in providing information about our products and services.

About XYZ Company:
- Founded in 2010
- Specializes in cloud computing solutions
- Headquartered in New York, with offices in London and Tokyo

Our Products:
1. CloudSafe - Data backup and security solution
2. CloudConnect - Integration platform for cloud services
3. CloudAnalytics - Business intelligence and analytics platform

Pricing:
- CloudSafe: $99/month
- CloudConnect: $149/month
- CloudAnalytics: $199/month

If customers ask about discounts, refer them to our sales team at sales@xyzcompany.com.

For technical support questions, always recommend contacting support@xyzcompany.com or calling our 24/7 support line at 1-800-XYZ-HELP.
```

### Example 2: FAQ Bot
```
You are a helpful FAQ assistant for our website visitors. Please answer questions based on the following FAQs. If you don't know the answer, suggest the visitor contact us at help@example.com.

Q: What are your business hours?
A: Our stores are open Monday-Friday from 9am to 6pm, and Saturday from 10am to 4pm. We are closed on Sundays.

Q: What is your return policy?
A: We offer a 30-day return policy on all unused items with original packaging and receipt.

Q: Do you ship internationally?
A: Yes, we ship to most countries. International shipping typically takes 7-14 business days.

Q: How do I track my order?
A: You can track your order by logging into your account or using the tracking number sent in your shipping confirmation email.

Q: Do you offer gift wrapping?
A: Yes, we offer gift wrapping for $5 per item. You can select this option during checkout.
```

## Advanced Customization

### Custom CSS
You can add custom CSS to your theme to further customize the appearance of the chatbot:

```css
/* Example custom CSS to add to your theme */
#wp-gpt-chatbot-container .wp-gpt-chatbot-button {
    /* Custom button styles */
    background-color: #ff6b6b !important;
}

#wp-gpt-chatbot-container .wp-gpt-chatbot-message.bot .wp-gpt-chatbot-message-content {
    /* Custom bot message styles */
    background-color: #f8f9fa !important;
    border: 1px solid #e9ecef !important;
}

#wp-gpt-chatbot-container .wp-gpt-chatbot-message.user .wp-gpt-chatbot-message-content {
    /* Custom user message styles */
    background-color: #4c6ef5 !important;
}
```

### Modifying the Plugin
If you need to make more substantial customizations:

1. Make a copy of the plugin folder (rename it to avoid updates overwriting your changes)
2. Modify the files as needed
3. Test thoroughly before deploying to production

## Implementation Options

### Floating Widget

The chatbot will automatically appear as a floating widget on all pages of your website once activated and configured. There is no need to add any code to your theme or pages.

### Inline Chatbot with Shortcode

You can also embed the chatbot directly within your page content using the provided shortcode:

```
[wp_gpt_chatbot]
```

The shortcode accepts the following optional parameters:

- `height`: The height of the chatbot container (default: '400px')
- `welcome_message`: Custom welcome message for this specific chatbot instance

Example usage with parameters:

```
[wp_gpt_chatbot height="500px" welcome_message="Hello! Ask me anything about our services."]
```

This allows you to place the chatbot in specific locations on your site, such as dedicated FAQ pages or support sections.

If you want to control which pages the chatbot appears on, you can use the following filter:

```php
// Add this to your theme's functions.php file or in a custom plugin

// Example 1: Disable chatbot on specific pages
add_filter('wp_gpt_chatbot_display', function($display) {
    if (is_page('contact') || is_page('about')) {
        return false; // Don't display chatbot on Contact or About pages
    }
    return $display;
});

// Example 2: Only show chatbot on specific pages
add_filter('wp_gpt_chatbot_display', function($display) {
    if (!is_page('faq') && !is_page('support')) {
        return false; // Only display chatbot on FAQ and Support pages
    }
    return $display;
});
```

## Multisite Implementation

For WordPress Multisite installations, you can:

1. Network Activate the plugin to enable it for all sites
2. Configure each site independently with its own API key and settings
3. Use different training material for each site to customize responses based on site content

## Troubleshooting

### The chatbot isn't appearing on my site
- Make sure you've entered a valid OpenAI API key
- Check if you have any JavaScript errors in the browser console
- Try disabling other plugins to check for conflicts

### The chatbot isn't responding properly
- Verify your OpenAI API key is valid and has sufficient credits
- Check that your training material is clear and comprehensive
- Try using a different OpenAI model

### The chatbot style doesn't match my site
- Adjust the primary and secondary colors in the plugin settings
- Add custom CSS to your theme as described in the Advanced Customization section

## Support

If you encounter any issues or have questions, please contact us at support@example.com.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- OpenAI for the ChatGPT API
- Icons from Feather Icons
