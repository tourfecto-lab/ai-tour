<?php

/**
 * Tourfecto - Client-Side Integrations Head Snippets
 * @version 1.0.0
 *
 * بيرجّع كود HTML (Script tags) للخدمات اللي بتشتغل في المتصفح
 * (Hotjar/Contentsquare، Mixpanel، OneSignal، Calendly) عشان تتحقن في
 * <head> اللوحة. كل snippet بيتحقن بس لو الخدمة مفعّلة ومهيّأة في .env.
 */

class ThirdPartyHead
{
    /**
     * إرجاع كل الـ snippets المفعّلة كـ HTML (سطر واحد لكل خدمة).
     */
    public static function render(): string
    {
        $html = '';

        if (defined('CONTENTSQUARE_ENABLED') && CONTENTSQUARE_ENABLED && defined('CONTENTSQUARE_TAG_ID') && CONTENTSQUARE_TAG_ID) {
            $tagId = htmlspecialchars((string) CONTENTSQUARE_TAG_ID, ENT_QUOTES, 'UTF-8');
            $html .= '<script async src="https://t.contentsquare.net/uxa/' . $tagId . '.js"></script>' . "\n";
        }

        if (defined('MIXPANEL_ENABLED') && MIXPANEL_ENABLED && defined('MIXPANEL_TOKEN') && MIXPANEL_TOKEN) {
            $token = htmlspecialchars((string) MIXPANEL_TOKEN, ENT_QUOTES, 'UTF-8');
            $html .= '<script>' . "\n"
                . '(function(f,b){if(!b.__SV){var e,g,i,h;window.mixpanel=b;b._i=[];b.init=function(e,f,c){function g(a,d){var b=d.split(".");2==b.length&&(a=a[b[0]],d=b[1]);a[d]=function(){a.push([d].concat(Array.prototype.slice.call(arguments,0)))}}var a=b;"undefined"!==typeof c?a=b[c]=[]:c="mixpanel";a.people=a.people||[];a.toString=function(a){var d="mixpanel";"mixpanel"!==c&&(d+="."+c);a||(d+=" (stub)");return d};a.people.toString=function(){return a.toString(1)+".people (stub)"};i="disable time_event track track_pageview track_links track_forms track_with_groups register register_once alias unregister identify name_tag set_config reset opt_in_tracking opt_out_tracking has_opted_in_tracking has_opted_out_tracking clear_opt_in_out_tracking start_batch_senders people.set people.set_once people.unset people.increment people.append people.union people.track_charge people.clear_charges people.delete_user people.remove".split(" ");for(h=0;h<i.length;h++)g(a,i[h]);b._i.push([e,f,c])};b.__SV=1.2;e=f.createElement("script");e.type="text/javascript";e.async=!0;e.src="https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js";g=f.getElementsByTagName("script")[0];g.parentNode.insertBefore(e,g)}})(document,window.mixpanel||[]);' . "\n"
                . 'mixpanel.init("' . $token . '");' . "\n"
                . '</script>' . "\n";
        }

        if (defined('ONESIGNAL_ENABLED') && ONESIGNAL_ENABLED && defined('ONESIGNAL_APP_ID') && ONESIGNAL_APP_ID) {
            $appId = htmlspecialchars((string) ONESIGNAL_APP_ID, ENT_QUOTES, 'UTF-8');
            $html .= '<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>' . "\n"
                . '<script>' . "\n"
                . 'window.OneSignalDeferred = window.OneSignalDeferred || [];' . "\n"
                . 'OneSignalDeferred.push(function(OneSignal){ OneSignal.init({ appId: "' . $appId . '" }); });' . "\n"
                . '</script>' . "\n";
        }

        if (defined('CALENDLY_ENABLED') && CALENDLY_ENABLED) {
            $html .= '<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>' . "\n";
        }

        return $html;
    }
}
