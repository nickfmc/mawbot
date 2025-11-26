# Google Analytics DataLayer Tracking Implementation

## Overview
This document describes the Google Analytics (GA4) dataLayer tracking events that have been implemented for the WP GPT Chatbot plugin.

## Implementation Date
November 26, 2025 (Updated with link tracking fixes)

## Recent Fixes (Nov 26, 2025)

### Link Tracking Issues Resolved:
1. **Event Delegation**: Links are now tracked using event delegation to catch dynamically added content
2. **Conversation History**: Fixed markdown formatting in conversation history so older messages have clickable links
3. **Timing Issues**: Link tracking is now applied after typing animation completes
4. **Debug Logging**: Added console logging to help troubleshoot tracking issues

### Key Improvements:
- Links in AI responses are tracked immediately when rendered
- Links added via typing animation are tracked after completion  
- All conversation history now properly formats and tracks links
- Event delegation prevents duplicate event handlers
- Debug console messages help verify tracking is working

## Events Tracked

All events are pushed to `window.dataLayer` with the event name `ai_chatbot_interaction` and additional metadata.

### 1. AI Search Box Clicks
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `search_box_clicked`

Tracks when users click into the AI search input field (inline chatbot only).

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'search_box_clicked',
  chatbot_type: 'inline'
}
```

### 2. Typing Started
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `typing_started`

Tracks when users start typing into the AI input field (both inline and popup chatbots). This event fires only once per interaction until the field is cleared or message is sent.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'typing_started',
  chatbot_type: 'inline' // or 'popup'
}
```

### 3. "Ask Us How" Button Clicks
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `ask_us_how_clicked`

Tracks when users submit a question using the "Ask Us How >" button in the inline chatbot.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'ask_us_how_clicked',
  question: 'The user\'s question text',
  chatbot_type: 'inline'
}
```

### 4. "Contact a Human" Button Clicks
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `contact_human_clicked`

Tracks when users click the "Contact a Human" button in the inline chatbot popup.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'contact_human_clicked',
  conversation_length: 4, // Number of messages in conversation
  chatbot_type: 'inline'
}
```

### 5. Links Clicked in AI Responses
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `link_clicked`

Tracks when users click on links within AI-generated responses (both inline and popup chatbots).

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'link_clicked',
  link_url: 'https://example.com/page',
  link_text: 'Link text that was clicked',
  link_location: 'ai_response' // or 'ai_response_inline'
}
```

### 6. Message Sent
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `message_sent`

Tracks when a user successfully sends a message to the AI chatbot.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'message_sent',
  message_length: 45, // Character count of the message
  conversation_length: 2 // Number of previous messages
}
```

### 7. Chatbot Opened
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `chatbot_opened`

Tracks when users open the popup chatbot widget.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'chatbot_opened',
  chatbot_type: 'popup'
}
```

### 8. Suggested Question Clicked
**Event Name:** `ai_chatbot_interaction`
**Interaction Type:** `suggested_question_clicked`

Tracks when users click on suggested question pills/buttons.

**Data Pushed:**
```javascript
{
  event: 'ai_chatbot_interaction',
  interaction_type: 'suggested_question_clicked',
  question: 'The suggested question text',
  chatbot_type: 'inline'
}
```

## Files Modified

1. **assets/js/chatbot.js**
   - Added `trackEvent()` helper function
   - Added typing detection for popup and inline chatbots
   - Added click tracking for search boxes
   - Added link click tracking in AI responses
   - Added message sent tracking

2. **wp-gpt-chatbot.php**
   - Added `trackEvent()` helper function to inline chatbot script
   - Added "Ask Us How" button click tracking
   - Added "Contact a Human" button click tracking
   - Added typing detection for inline input
   - Added search box click tracking
   - Added link click tracking in inline popup responses
   - Added suggested question pill click tracking

## Google Tag Manager Setup

To use these events in Google Tag Manager (GTM):

1. **Create a Custom Event Trigger:**
   - Trigger Type: Custom Event
   - Event Name: `ai_chatbot_interaction`

2. **Create Variables for Event Properties:**
   - Variable Type: Data Layer Variable
   - Data Layer Variable Name: 
     - `interaction_type`
     - `chatbot_type`
     - `link_url`
     - `link_text`
     - `question`
     - `conversation_length`
     - `message_length`

3. **Create GA4 Event Tags:**
   - Tag Type: Google Analytics: GA4 Event
   - Event Name: `ai_chatbot_interaction`
   - Event Parameters: Map the dataLayer variables to GA4 event parameters

4. **Set up the trigger** to fire on the custom event `ai_chatbot_interaction`

## Key Metrics You Can Track

### User Engagement Metrics:
- **Total AI Tool Users:** Count of unique users with `typing_started` event
- **Conversion Rate:** Users who click CTAs / Total users who typed
- **Support Escalation Rate:** Users who clicked "Contact a Human" / Total conversations
- **Link Click-Through Rate:** Link clicks / Total messages with links
- **Question Submission Rate:** Messages sent / Users who started typing

### Chatbot Performance:
- **Average Conversation Length:** Average `conversation_length` when users contact support
- **Popular Questions:** Most common `question` values from "Ask Us How" clicks
- **Suggested Questions Effectiveness:** Click rate on suggested question pills
- **Chatbot Open Rate:** How often users open the popup chatbot

### User Behavior:
- **Drop-off Analysis:** Users who type but don't submit vs. those who submit
- **Link Engagement:** Which links in responses get the most clicks
- **CTA Performance:** Which suggested questions drive the most engagement

## Testing

To verify the tracking is working:

1. **Open Browser Developer Console:**
   - Press F12 or right-click → Inspect → Console tab
   
2. **Check DataLayer:**
   - Type: `window.dataLayer` and press Enter to see the current state
   
3. **Test Interactions:**
   - Interact with the chatbot (type, click buttons, click links)
   - Watch the console for "GA Event:" messages
   - Check the dataLayer array for new events with `ai_chatbot_interaction`

4. **Common Issues & Solutions:**

   **Problem: Link clicks not tracking**
   - **Solution**: Links in AI responses use event delegation and are tracked dynamically
   - **Check**: Look for "Link clicked:" messages in console when clicking links
   - **Verify**: Make sure the AI response actually contains proper HTML `<a>` tags

   **Problem: No dataLayer events appearing**  
   - **Solution**: Check console for "GA Event:" debug messages
   - **Verify**: Ensure `window.dataLayer` exists (it's created automatically)
   - **Debug**: Try manually calling `trackEvent('test', {type: 'manual'})`

   **Problem: Links not clickable in conversation history**
   - **Solution**: Updated to use `formatMarkdown()` function for all assistant messages
   - **Check**: Previous conversations should now have properly formatted links

Example expected console output:
```javascript
GA Event: ai_chatbot_interaction {interaction_type: "typing_started", chatbot_type: "inline"}
Link clicked: https://example.com Contact Us Page  
GA Event: ai_chatbot_interaction {interaction_type: "link_clicked", link_url: "https://example.com", link_text: "Contact Us Page", link_location: "ai_response_inline"}
```

Example dataLayer contents:
```javascript
[
  {
    event: 'ai_chatbot_interaction',
    interaction_type: 'typing_started',
    chatbot_type: 'inline'
  },
  {
    event: 'ai_chatbot_interaction',
    interaction_type: 'link_clicked',
    link_url: 'https://example.com/contact',
    link_text: 'Contact Us Page',
    link_location: 'ai_response_inline'
  }
  // ... more events
]
```

## Notes

- All tracking uses Google's recommended dataLayer pattern
- Events are non-blocking and won't impact chatbot performance
- The `typing_started` event has built-in debouncing to fire only once per interaction
- Link tracking is automatically applied to all links in AI responses
- No personal data (like actual message content for regular messages) is tracked to respect user privacy
