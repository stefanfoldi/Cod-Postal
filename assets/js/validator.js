(function ($) {
    'use strict';

    if (typeof WCRoSafe === 'undefined') {
        return;
    }

    var messages = WCRoSafe.messages || {};
    var postcodeRequest = null;
    var postcodeTimer = null;
    var cuiTimer = null;

    function field(name) {
        return $('#' + name + ', [name="' + name + '"]').first();
    }

    function setNotice(target, message, type) {
        var id = target.attr('id') || target.attr('name');
        var notice = $('#wc-ro-safe-' + id + '-notice');

        if (!notice.length) {
            notice = $('<span/>', {
                id: 'wc-ro-safe-' + id + '-notice',
                class: 'wc-ro-safe-notice'
            }).insertAfter(target);
        }

        notice.removeClass('is-error is-success is-loading');
        if (type) {
            notice.addClass('is-' + type);
        }
        notice.text(message || '');
    }

    function toggleCompanyFields() {
        var invoiceType = field('billing_invoice_type').val();
        var isCompany = invoiceType === 'pj';

        $('.wc-ro-safe-pj, .form-row:has(.wc-ro-safe-pj)').toggle(isCompany);
        if (!isCompany) {
            field('billing_cui').val('');
            field('billing_reg_com').val('');
            setNotice(field('billing_cui'), '');
        }
    }

    function updatePostcodeOptions(codes) {
        var postcode = field('billing_postcode');
        var current = postcode.val();

        if (!postcode.length || !postcode.is('select')) {
            return;
        }

        postcode.empty().append($('<option/>', {
            value: '',
            text: 'Selectează codul poștal...'
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

        if (!postcode.length || !postcode.is('select')) {
            return;
        }

        if (!city) {
            updatePostcodeOptions([]);
            setNotice(postcode, messages.select_city || 'Selectează mai întâi localitatea', 'error');
            return;
        }

        if (postcodeRequest && postcodeRequest.readyState !== 4) {
            postcodeRequest.abort();
        }

        setNotice(postcode, messages.loading || 'Se încarcă...', 'loading');

        postcodeRequest = $.post(WCRoSafe.ajax_url, {
            action: 'wc_ro_safe_get_postcodes',
            nonce: WCRoSafe.nonce,
            city: city,
            state: field('billing_state').val(),
            addr1: field('billing_address_1').val(),
            addr2: field('billing_address_2').val()
        }).done(function (response) {
            var codes = response && response.success && response.data ? response.data.codes : [];
            updatePostcodeOptions(codes);
            setNotice(postcode, codes.length ? '' : (messages.no_postal || 'Nu s-au găsit coduri - introdu manual'), codes.length ? 'success' : 'error');
        }).fail(function (xhr, status) {
            if (status === 'abort') {
                return;
            }
            setNotice(postcode, messages.api_error || 'Nu am putut verifica acum (poți continua comanda)', 'error');
        });
    }

    function loadCompany() {
        var cui = field('billing_cui');
        var value = cui.val();

        if (!value || value.replace(/\D/g, '').length < 4) {
            setNotice(cui, '');
            return;
        }

        setNotice(cui, messages.cui_loading || 'Se verifică CUI...', 'loading');

        $.post(WCRoSafe.ajax_url, {
            action: 'wc_ro_safe_get_company',
            nonce: WCRoSafe.nonce,
            cui: value
        }).done(function (response) {
            var data = response && response.success ? response.data : null;

            if (!data) {
                setNotice(cui, messages.cui_invalid || 'Nu am găsit firma pe acest CUI (poți continua comanda)', 'error');
                return;
            }

            if (data.company) {
                field('billing_company').val(data.company).trigger('change');
            }
            if (data.address) {
                field('billing_address_1').val(data.address).trigger('change');
            }
            if (data.reg_com) {
                field('billing_reg_com').val(data.reg_com).trigger('change');
            }
            if (data.city) {
                field('billing_city').val(data.city).trigger('change');
            }
            if (data.postal_code) {
                updatePostcodeOptions([{ code: data.postal_code, label: data.postal_code }]);
                field('billing_postcode').val(data.postal_code).trigger('change');
            }

            setNotice(cui, messages.cui_valid || 'Date companie încărcate', 'success');
        }).fail(function () {
            setNotice(cui, messages.api_error || 'Nu am putut verifica acum (poți continua comanda)', 'error');
        });
    }

    $(function () {
        toggleCompanyFields();

        $(document.body).on('change', '#billing_invoice_type, [name="billing_invoice_type"]', toggleCompanyFields);
        $(document.body).on('change keyup', '#billing_city, #billing_state, #billing_address_1, #billing_address_2', function () {
            window.clearTimeout(postcodeTimer);
            postcodeTimer = window.setTimeout(loadPostcodes, 400);
        });
        $(document.body).on('change keyup', '#billing_cui, [name="billing_cui"]', function () {
            window.clearTimeout(cuiTimer);
            cuiTimer = window.setTimeout(loadCompany, 500);
        });
    });
})(jQuery);
