(function ($) {
    'use strict';

    if (typeof WCRoSafe === 'undefined') {
        return;
    }

    var messages = WCRoSafe.messages || {};
    var postcodeRequest = null;
    var postcodeTimer = null;
    var cuiTimer = null;
    var manualPostcode = false;

    function field(name) {
        return $('#' + name + ', [name="' + name + '"]').first();
    }

    function setNotice(target, message, type) {
        if (!target.length) {
            return;
        }

        var id = target.attr('id') || target.attr('name');
        var notice = $('#wc-ro-safe-' + id + '-notice');

        if (!notice.length) {
            notice = $('<span/>', {
                id: 'wc-ro-safe-' + id + '-notice',
                'class': 'wc-ro-safe-notice'
            }).insertAfter(target);
        } else {
            notice.insertAfter(target);
        }

        notice.removeClass('is-error is-success is-loading');
        if (type) {
            notice.addClass('is-' + type);
        }
        notice.text(message || '');
    }

    function isCompany() {
        return field('billing_invoice_type').val() === 'pj';
    }

    function toggleCompanyFields(clearOnHide) {
        var company = isCompany();
        var cui = field('billing_cui');

        $('.wc-ro-safe-pj').closest('.form-row').toggle(company);
        cui.prop('required', company);
        field('billing_company').prop('required', company);

        if (!company && clearOnHide === true) {
            cui.val('');
            field('billing_reg_com').val('');
            setNotice(cui, '');
        }
    }

    /**
     * Replaces the postcode dropdown with a free text input so the customer can
     * always complete the order, even when the lookup service returns nothing.
     */
    function toManualPostcode(message, type) {
        var postcode = field('billing_postcode');
        var current = postcode.val() || '';

        if (postcode.is('select')) {
            postcode.replaceWith($('<input/>', {
                type: 'text',
                id: 'billing_postcode',
                name: 'billing_postcode',
                'class': 'input-text',
                maxlength: 10,
                autocomplete: 'postal-code',
                placeholder: messages.manual_placeholder || '',
                value: current
            }));
        }

        manualPostcode = true;
        setNotice(field('billing_postcode'), message, type || 'error');
    }

    function toSelectPostcode() {
        var postcode = field('billing_postcode');
        var current = postcode.val() || '';

        if (!postcode.is('select')) {
            postcode.replaceWith($('<select/>', {
                id: 'billing_postcode',
                name: 'billing_postcode',
                'class': 'select'
            }));
        }

        manualPostcode = false;

        return current;
    }

    function updatePostcodeOptions(codes) {
        var current = toSelectPostcode();
        var postcode = field('billing_postcode');

        postcode.empty().append($('<option/>', {
            value: '',
            text: messages.select_postcode || 'Selectează codul poștal...'
        }));

        $.each(codes || [], function (_, item) {
            if (!item || !item.code) {
                return;
            }
            postcode.append($('<option/>', {
                value: item.code,
                text: item.label || item.code
            }));
        });

        if (current && postcode.find('option[value="' + current + '"]').length) {
            postcode.val(current);
        }

        postcode.trigger('change');
    }

    function loadPostcodes() {
        var city = field('billing_city').val();
        var postcode = field('billing_postcode');

        if (!postcode.length) {
            return;
        }

        if (!city) {
            if (!manualPostcode) {
                updatePostcodeOptions([]);
                setNotice(field('billing_postcode'), messages.select_city || '', 'error');
            }
            return;
        }

        if (postcodeRequest && postcodeRequest.readyState !== 4) {
            postcodeRequest.abort();
        }

        setNotice(postcode, messages.loading || '', 'loading');

        postcodeRequest = $.post(WCRoSafe.ajax_url, {
            action: 'wc_ro_safe_get_postcodes',
            nonce: WCRoSafe.nonce,
            city: city,
            state: field('billing_state').val(),
            addr1: field('billing_address_1').val(),
            addr2: field('billing_address_2').val()
        }).done(function (response) {
            var codes = response && response.success && response.data ? response.data.codes : [];

            if (!codes || !codes.length) {
                toManualPostcode(messages.no_postal || '');
                return;
            }

            updatePostcodeOptions(codes);
            setNotice(field('billing_postcode'), '', 'success');
        }).fail(function (xhr, status) {
            if (status === 'abort') {
                return;
            }
            toManualPostcode(messages.api_error || '');
        });
    }

    function schedulePostcodes() {
        window.clearTimeout(postcodeTimer);
        postcodeTimer = window.setTimeout(loadPostcodes, 600);
    }

    function applyCompanyData(data) {
        if (data.company) {
            field('billing_company').val(data.company).trigger('change');
        }
        if (data.address) {
            field('billing_address_1').val(data.address).trigger('change');
        }
        if (data.reg_com) {
            field('billing_reg_com').val(data.reg_com).trigger('change');
        }
        if (data.state_code) {
            field('billing_state').val(data.state_code).trigger('change');
        }
        if (data.city) {
            field('billing_city').val(data.city).trigger('change');
        }
        if (data.postal_code) {
            if (manualPostcode) {
                field('billing_postcode').val(data.postal_code).trigger('change');
            } else {
                updatePostcodeOptions([{ code: data.postal_code, label: data.postal_code }]);
                field('billing_postcode').val(data.postal_code).trigger('change');
            }
        }
    }

    function loadCompany() {
        var cui = field('billing_cui');
        var value = cui.val();

        if (!isCompany() || !value || value.replace(/\D/g, '').length < 4) {
            setNotice(cui, '');
            return;
        }

        setNotice(cui, messages.cui_loading || '', 'loading');

        $.post(WCRoSafe.ajax_url, {
            action: 'wc_ro_safe_get_company',
            nonce: WCRoSafe.nonce,
            cui: value
        }).done(function (response) {
            var data = response && response.success ? response.data : null;
            var serverMessage = response && response.data ? response.data.message : '';

            if (!data || !data.company) {
                setNotice(cui, serverMessage || messages.cui_invalid || '', 'error');
                return;
            }

            applyCompanyData(data);
            setNotice(cui, messages.cui_valid || '', 'success');
            schedulePostcodes();
        }).fail(function () {
            setNotice(cui, messages.api_error || '', 'error');
        });
    }

    $(function () {
        toggleCompanyFields(false);

        $(document.body).on('change', '#billing_invoice_type, [name="billing_invoice_type"]', function () {
            toggleCompanyFields(true);
        });

        $(document.body).on('change', '#billing_country', function () {
            if ($(this).val() !== 'RO') {
                toManualPostcode('', 'loading');
            }
        });

        $(document.body).on('change', '#billing_city, #billing_state', schedulePostcodes);
        $(document.body).on('change blur', '#billing_address_1, #billing_address_2', schedulePostcodes);

        $(document.body).on('input', '#billing_cui, [name="billing_cui"]', function () {
            window.clearTimeout(cuiTimer);
            cuiTimer = window.setTimeout(loadCompany, 900);
        });
        $(document.body).on('blur', '#billing_cui, [name="billing_cui"]', function () {
            window.clearTimeout(cuiTimer);
            loadCompany();
        });

        // WooCommerce re-renders the checkout fields on every fragment refresh,
        // which drops the inline styles and required flags applied above.
        $(document.body).on('updated_checkout', function () {
            manualPostcode = !field('billing_postcode').is('select');
            toggleCompanyFields(false);
        });
    });
})(jQuery);
