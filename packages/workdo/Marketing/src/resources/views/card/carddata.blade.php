@php
    $social_no = 1;
    $appointment_no = 0;
    $service_row_no = 0;
    $testimonials_row_no = 0;
    $gallery_row_no = 0;
    $product_row_no = 0;
    $no = 1;
     $business = $store ;
    $stringid = $store->id;
    
    $is_enable = false;
    $is_contact_enable = false;
    $is_enable_appoinment = false;
    $is_enable_service = false;
    $is_enable_product = false;
    $is_enable_testimonials = false;
    $is_enable_sociallinks = false;
    $is_custom_html_enable = false;
    $is_enable_gallery = false;
    $is_payment = false;
    $company_setting['custom_html_text'] = $company_setting['custom_html_text'] ?? "";
    $company_setting['gdpr_text']=$company_setting['gdpr_text'] ?? "";
    $company_setting['is_custom_html_enabled']= $company_setting['is_custom_html_enabled'] ?? "";
    $company_setting['is_branding_enabled']=$company_setting['is_branding_enabled'] ??"";
    $company_setting['is_gdpr_enabled']=$company_setting['is_gdpr_enabled'] ?? "";
    $company_setting['metaimage'] = $company_setting['metaimage'] ?? "";
    $customhtml["is_custom_html_enabled"] = $customhtml["is_custom_html_enabled"] ?? "";
    $company_setting['branding_text'] = $company_setting['branding_text'] ?? "";
    $company_setting['metakeyword'] = $company_setting['metakeyword'] ?? "" ;
    $company_setting['metadesc'] = $company_setting['metadesc'] ?? "";
    $company_setting['google_fonts'] = $company_setting['google_fonts'] ?? "";
    $company_setting['customcss'] = $company_setting['customcss'] ?? "";
    $company_setting['enable_pwa_business']=$company_setting['enable_pwa_business'] ?? "";
    $company_setting['google_analytic']= $company_setting['google_analytic'] ?? "";
    $company_setting['fbpixel_code'] = $company_setting['fbpixel_code'] ?? "" ;
    $company_setting['customjs'] = $company_setting['customjs'] ?? "";
    $company_setting["enable_password"] = $company_setting["enable_password"] ?? ""; 
    $company_setting["password"] = $company_setting["password"] ?? ""; 
    $custom_html = $company_setting['custom_html_text'];
    $is_branding_enabled = false;
    $branding =  $company_setting['branding_text'];
  
    $is_gdpr_enabled = false;
    $gdpr_text =  $company_setting['gdpr_text'];
    $device_type = "desktop";
    $card_theme = json_decode($store->card_theme);

    $company_favicon = get_module_img('Marketing');

    if (!is_null($contactinfo) && !is_null($contactinfo_content)) {
        $contactinfo['is_enabled'] == '1' ? ($is_contact_enable = true) : ($is_contact_enable = false);
    }

    if (!is_null($business_hours) && !is_null($businesshours)) {
        $businesshours['is_enabled'] == '1' ? ($is_enable = true) : ($is_enable = false);
    }

    if (!is_null($appoinment_hours) && !is_null($appoinment)) {
        $appoinment['is_enabled'] == '1' ? ($is_enable_appoinment = true) : ($is_enable_appoinment = false);
    }

    if (!is_null($services_content) && !is_null($services)) {
        $services['is_enabled'] == '1' ? ($is_enable_service = true) : ($is_enable_service = false);
    }
    if (!is_null($products_content) && !is_null($products)) {
        $products['is_enabled'] == '1' ? ($is_enable_product = true) : ($is_enable_product = false);
    }
    if (!is_null($testimonials_content) && !is_null($testimonials)) {
        $testimonials['is_enabled'] == '1' ? ($is_enable_testimonials = true) : ($is_enable_testimonials = false);
    }

    if (!is_null($social_content) && !is_null($sociallinks)) {
        $sociallinks['is_enabled'] == '1' ? ($is_enable_sociallinks = true) : ($is_enable_sociallinks = false);
    }

    if (!is_null($gallery_contents) && !is_null($gallery)) {
        $gallery['is_enabled'] == '1' ? ($is_enable_gallery = true) : ($is_enable_gallery = false);
    }
    if (!is_null($cardPayment_content) && !is_null($cardPayment)) {
        $cardPayment['is_enabled'] == '1' ? ($is_payment = true) : ($is_payment = false);
    }

    if (!is_null($custom_html) && !is_null($customhtml)) {
      $company_setting['is_custom_html_enabled'] == '1' ? ($is_custom_html_enable = true) : ($is_custom_html_enable = false);
    }

    if (!is_null( $company_setting['is_branding_enabled']) && !is_null($company_setting['is_branding_enabled'])) {
        !empty($company_setting['is_branding_enabled']) && $company_setting['is_branding_enabled'] == 'on'
            ? ($is_branding_enabled = true)
            : ($is_branding_enabled = false);
    } else {
        $is_branding_enabled = false;
    }
    if (!is_null($company_setting['is_gdpr_enabled']) && !is_null($company_setting['is_gdpr_enabled'])) {
        !empty($company_setting['is_gdpr_enabled']) && $company_setting['is_gdpr_enabled'] == 'on'
            ? ($is_gdpr_enabled = true)
            : ($is_gdpr_enabled = false);
    }

    $color = substr($store->theme_color, 0, 6);

    $SITE_RTL = getAdminAllSetting($store->created_by,$store->id);
            if (isset($SITE_RTL['SITE_RTL'])) {
                $SITE_RTL = $SITE_RTL['SITE_RTL'];
            } else {
                $SITE_RTL = 'off';
            }
    $url_link =route('get.vcard', ['slug' => $store->slug]);
    $meta_tag_image = get_file($company_setting['metaimage']);

    // Cookie
    $cookie_data = $company_setting['gdpr_text'];
    $a = $cookie_data;
     

@endphp