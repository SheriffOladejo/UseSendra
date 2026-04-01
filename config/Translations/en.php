<?php

return [


    // Common buttons
    'button_send_package' => "📦 Send Package",
    'button_delivery_rider' => "🏍 Delivery Rider",
    'button_motorcycle' => "🏍️ Motorcycle",
    'button_keke' => "🛺 Keke",
    'button_confirm_location' => "✅ Confirm Location",

    // User greetings & onboarding
    'start_greeting' => "Hello 🙋🏽‍♀️ {name}! Welcome to *Sendra Courier*.\n\nLet's get started by selecting your preferred language.",
    'more_languages' => "More languages:",
    'language_selected' => "✅ You have selected {language} as your language.",
    'language_selection_error' => "Please select a language by tapping a button.",
    'welcome_message' => "Hello 🙋🏽‍♀️ {name}! Welcome to *Sendra Courier* \n\nLet’s get you set up real quick.\n\nAre you here to send packages or deliver them?",
    'help_menu' => "I can help you send packages fast right here on WhatsApp.\n\nType one of the following to continue:\n/send — To send a package 📦\n/track — To track your order 📍\n/help — To get help ❓\n/history — To see your delivery history 🕘",
    'unrecognized_option' => "I didn't understand that. Please choose one of the options below to get started.",
    'rider_name_prompt' => "Great! Let's set up your rider profile.\n\nPlease enter your first name and last name as it appears on your ID (e.g. Chinedu Sani Adeyemi).",

    // Choose Role Step
    'choose_role_prompt' => "Are you here to send packages or deliver them?",
    'choose_role_invalid' => "I didn't understand that. Please choose one of the options below to get started.",
    'role_sender' => "📦 Send Package",
    'role_rider' => "🏍 Delivery Rider",
    'sender_menu_intro' =>
    "I can help you send packages fast right here on WhatsApp.\n\n" .
    "Type one of the following to continue:\n" .
    "/send — To send a package 📦\n" .
    "/track — To track your order 📍\n" .
    "/help — To get help ❓\n" .
    "/history — To see your delivery history 🕘",

    'rider_profile_setup' => "Great! Let's set up your rider profile.\n\nPlease enter your *full name* exactly as it appears on your ID (e.g. Chinedu Sani Adeyemi).",
    // Full Name Step
    'enter_full_name' => "Please enter your full name (e.g. *Chinedu Sani Adeyemi*).",

    'nice_to_meet_you' => "Nice to meet you *{name}! 🚴\n\nWhat phone number should customers call you on?",
    'full_name_invalid' => "Please enter your full name (e.g. *Chinedu Sani Adeyemi*).",
    'full_name_success' => "Nice to meet you *:firstName*! 🚴\n\nWhat phone number should customers call you on?",

    // BVN Step
    'bvn_prompt' => "Great! 🆔\n\nNow, please provide your *NIN (National Identity Number)*.\n\nEnter your 11-digit NIN:",
    'bvn_invalid' => "Please enter a valid 11-digit BVN number.",

    // NIN Step
    'nin_prompt' => "Nice, Now upload a picture of your *rider's card/drivers license or hackney permit*",
    'nin_invalid' => "Please enter a valid 11-digit NIN number.",

    // Phone Step
    'phone_prompt' => "Excellent🎯! Where do you usually operate from?  (This will help us route orders to you easily). \n\nUse Whatsapp's location📍 feature to select the location on the map\n1. Tap the '+' icon\n2. Select 'Location'\n3. Choose 'Send your current location' or pick a spot on the map\n\nNo worries, you can always change it anytime",
    'phone_invalid' => "Please enter a valid phone number.",
    'rider_location_request' =>
    "Excellent 🎯! Where do you usually operate from?\n\nSend your location 📍:\n1. Tap '+'\n2. Choose 'Location'\n3. Send current location or pick a spot.\n\nYou can change it anytime.",

    'invalid_phone' => "Please enter a valid phone number.",

    // Location Steps
    'location_not_found' => "📍 We couldn't get your location. \n\nPlease use WhatsApp's location sharing feature:\n1. Tap the '+' icon\n2. Select 'Location'\n3. Choose 'Send your current location' or pick a spot on the map\n\nThis helps us send you nearby ride requests!",
    'location_confirm_button' => "If it's correct tap *Confirm*. Otherwise send a new location.",
    'location_invalid' => "📍 We couldn't get your location. Please send your location via WhatsApp.",
    'location_confirm_prompt' => "Is this your preferred bus-stop location📍?",
    'location_confirm_button_prompt' => "If correct, tap *Confirm*. Otherwise send a new location.",
    'confirm_location' => "✅ Confirm Location",

    // Confirm Location Step
    'confirm_location_error' => "Please tap *Confirm Location* or send a new location.",
    'vehicle_prompt'               => "Great! 🚀\nNow tell us which vehicle you use for deliveries.",
    'vehicle_motorcycle'           => "🏍️ Motorcycle",
    'vehicle_keke'                 => "🛺 Keke",

    // License Steps
    'license_number_invalid' => "Please enter a valid driver's license number.",
    'license_picture_prompt' => "📄 We need a photo of your driver's license.\n\nPlease send a clear picture showing:\n✅ All text is readable\n✅ License number is visible\n✅ Expiry date is visible\n✅ Photo is not blurry\n\nTap the '+' icon and select 'Camera' or 'Gallery' to upload.",
    'license_picture_received' => "Perfect! \n\n📄 Driver's license received. \n\nNext, we need you to enter your vehicle's plate number. Use format KSF-764-SF or KSF764SF",

    // Plate Number Step
    'plate_number_prompt' => "Great! 🚗  \n\nNow, what's the color of your vehicle?  \n\nPlease type the color (e.g., 'Black', 'White', 'Red', 'Blue', etc.)",
    'plate_number_invalid' => "Please enter a valid Nigerian vehicle plate number (e.g., ABC-123DE or ABC123DE).",

    // Vehicle Steps
    'vehicle_picture_prompt' => "📷 We need a photo of your vehicle.\n\nPlease send a clear picture showing:\n- Full vehicle (front or side view)\n- Visible plate number\n- Good lighting\n\nTap the '+' icon and select 'Camera' or 'Gallery' to upload.",
    'vehicle_picture_received' => "Great!\n\n🚗 Vehicle photo received.\n\nNow, please tell us the color of your vehicle. For example: Black, Silver, Red.",
    'vehicle_color_invalid' => "Please enter a valid vehicle color (letters only, e.g., 'Black', 'Silver', 'Red').",
    'vehicle_color_prompt' => "Perfect! 📝\n\nNext, provide the brand of your vehicle. For example: Bajaj, TVS-HLX",
    'vehicle_brand_invalid' => "Please enter your vehicle brand and model (e.g., TVS HLX 125 or Honda Activa).",

    // Vehicle Type Step
    'vehicle_type_prompt' => "Excellent! 🎯 .\n\nTo keep things safe for everyone, we’ll need to verify your details, this should take less than 2 minutes. First enter your NIN ",
    'vehicle_type_invalid' => "Sorry, that's not a valid vehicle type. Please choose one of the following:",
    'invalid_vehicle_type' => "Sorry, that's not a valid vehicle type. Please choose one of the following:",
    'vehicle_motorcycle'  => "🏍️ Motorcycle",
    'vehicle_keke'        => "🛺 Keke",
    
    // Selfie Step
    'selfie_invalid' => "Please send a valid image. Make sure to send it as a photo attachment.",
    'selfie_success' => "🎉 *Registration Complete!*  \n\nThank you for submitting your information!  \n\nYour application is now under review. Our team will verify your details and get back to you within 24-48 hours.  \n\nYou'll receive a notification once your account is *approved* and you can start accepting rides.  \n\nWelcome to the team! 🚀",

    // In Review / Ready Steps
    'in_review_message' => "⏳ *We’re reviewing your details…*\nThis usually takes *2–4 working days*.\n\nWhile you wait, here’s how *Sendra Logistics* works 👇\n\n━━━━━━━━━━━━━━━━━━━━\n\n🚚 *When a delivery request comes in*\n• You’ll receive the request instantly on WhatsApp\n• You can *accept the price* or *send your own offer*\n\n💰 *If a customer chooses your offer*\n• You’ll pay a *10% deposit* to secure the delivery\n• Once confirmed, you can proceed to the *pickup location*\n\n📦 *At pickup*\n• Ask the customer to show their *Sendra QR code*\n• Scan it to confirm the package is legitimate before delivery\n\n━━━━━━━━━━━━━━━━━━━━\n\n🎉 Once your review is complete, you’ll start receiving delivery requests right away.\n\nThanks for joining *Sendra* — we’re excited to have you on board!",
    'in_review_approved' => "🎉 *Congratulations!* Your account has been approved! \n\nYou can now start accepting ride requests. \n\nType *'online'* to go online and start receiving requests.\nType *'help'* to see available commands.",
    'in_review_rejected' => "❌ Unfortunately, your application was not approved.\n\n*Reason:* :reason\n\nYou can reapply by typing *'reapply'* or contact support for assistance.",
    'in_review_driver_not_found' => "❌ Driver record not found. Please contact support.",

    // Validation error
    'invalid_input' => "⚠️ Invalid input. Please try again.",

];