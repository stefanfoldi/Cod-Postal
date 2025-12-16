jQuery(function($){
  function fields(){
    return {
      invoiceType: $('#billing_invoice_type'),
      cui: $('#billing_cui'),
      company: $('#billing_company'),
      regcom: $('#billing_reg_com'),
      city: $('#billing_city'),
      state: $('#billing_state'),
      addr1: $('#billing_address_1'),
      addr2: $('#billing_address_2'),
      postcode: $('#billing_postcode'),
    };
  }

  let cuiTimer, loadTimer;

  function showMsg($field, text, kind){
    if (!$field || !$field.length) return;
    const $row = $field.closest('.form-row');
    $row.find('.wc-ro-safe-msg').remove();
    const icon = kind === 'success' ? '✓' : (kind === 'error' ? '✗' : 'ℹ');
    $row.append('<div class="wc-ro-safe-msg wc-ro-safe-' + kind + '">' + icon + ' ' + text + '</div>');
    if (kind === 'success') {
      setTimeout(function(){ $row.find('.wc-ro-safe-msg').fadeOut(250, function(){ $(this).remove(); }); }, 3500);
    }
  }

  function togglePJ(){
    const f = fields();
    const type = f.invoiceType.val();
    if (type === 'pj') {
      $('.wc-ro-safe-pj').closest('.form-row').show();
      f.cui.attr('required', true);
      f.company.attr('required', true);
    } else {
      $('.wc-ro-safe-pj').closest('.form-row').hide();
      f.cui.attr('required', false);
      f.company.attr('required', false);
      f.regcom.attr('required', false);
    }
  }

  function toManualPostcode(note){
    const f = fields();
    const $pf = f.postcode;
    if (!$pf.length) return;
    const html = '<input type="text" id="billing_postcode" name="billing_postcode" class="input-text" placeholder="Introdu codul poștal" maxlength="6" />';
    $pf.replaceWith(html);
    const $new = $('#billing_postcode');
    if (note) showMsg($new, note, 'info');
  }

  function loadPostcodes(){
    const f = fields();
    const city = f.city.val();
    const state = f.state.val();
    const addr1 = f.addr1.length ? f.addr1.val() : '';
    const addr2 = f.addr2.length ? f.addr2.val() : '';
    const $pf = f.postcode;

    if (!city) {
      if ($pf.is('select')) $pf.html('<option value="">' + WCRoSafe.messages.select_city + '</option>').prop('disabled', true);
      return;
    }
    if (!$pf.is('select')) return;

    $pf.prop('disabled', true).html('<option value="">' + WCRoSafe.messages.loading + '</option>');

    $.ajax({
      url: WCRoSafe.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: { action:'wc_ro_safe_get_postcodes', nonce:WCRoSafe.nonce, city:city, state:state, addr1:addr1, addr2:addr2 },
      success: function(res){
        if (!res || !res.success) { toManualPostcode(WCRoSafe.messages.api_error); return; }
        const codes = (res.data && res.data.codes) ? res.data.codes : [];
        if (!codes.length) { toManualPostcode(WCRoSafe.messages.no_postal); return; }

        let opts = '<option value="">Selectează codul poștal...</option>';
        codes.forEach(function(it){ opts += '<option value="' + it.code + '">' + it.label + '</option>'; });
        const $pf2 = $('#billing_postcode');
        if ($pf2.is('select')) $pf2.html(opts).prop('disabled', false);
      },
      error: function(){ toManualPostcode(WCRoSafe.messages.api_error); }
    });
  }

  function scheduleLoad(){ clearTimeout(loadTimer); loadTimer = setTimeout(loadPostcodes, 450); }

  function checkCUI(){
    const f = fields();
    let cui = (f.cui.val() || '').trim().toUpperCase();
    cui = cui.replace(/[^0-9]/g,'');
    if (!cui || cui.length < 4) return;

    f.cui.addClass('wc-ro-safe-loading');
    showMsg(f.cui, WCRoSafe.messages.cui_loading, 'info');

    $.ajax({
      url: WCRoSafe.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: { action:'wc_ro_safe_get_company', nonce:WCRoSafe.nonce, cui:cui },
      success: function(res){
        const f2 = fields();
        f2.cui.removeClass('wc-ro-safe-loading');

        if (res && res.success) {
          const d = res.data || {};
          if (d.company) f2.company.val(d.company);
          if (d.reg_com) f2.regcom.val(d.reg_com);
          if (d.address && f2.addr1.length) f2.addr1.val(d.address);
          if (d.city) f2.city.val(d.city);
          if (d.county) f2.state.val(d.county);
          if (d.postal_code) setTimeout(function(){ $('#billing_postcode').val(d.postal_code); }, 500);

          showMsg(f2.cui, WCRoSafe.messages.cui_valid, 'success');
          scheduleLoad();
          return;
        }

        const serverMsg = (res && res.data && res.data.message) ? res.data.message : '';
        showMsg(f2.cui, serverMsg || WCRoSafe.messages.cui_invalid, 'warning');
      },
      error: function(){
        const f2 = fields();
        f2.cui.removeClass('wc-ro-safe-loading');
        showMsg(f2.cui, WCRoSafe.messages.api_error, 'warning');
      }
    });
  }

  $(document.body).on('change', '#billing_invoice_type', function(){ togglePJ(); });
  $(document.body).on('input', '#billing_cui', function(){
    clearTimeout(cuiTimer);
    if (($(this).val()||'').trim().length >= 4) cuiTimer = setTimeout(checkCUI, 900);
  });
  $(document.body).on('blur', '#billing_cui', function(){
    if (($(this).val()||'').trim().length >= 4) { clearTimeout(cuiTimer); checkCUI(); }
  });

  $(document.body).on('change', '#billing_city, #billing_state', scheduleLoad);
  $(document.body).on('blur change', '#billing_address_1, #billing_address_2', scheduleLoad);

  // NU mai declanșăm scheduleLoad la updated_checkout (evităm loop)
  $(document.body).on('updated_checkout', function(){ togglePJ();
    // Re-try once after checkout refresh (no loop because we don't trigger update_checkout)
    try {
      if (jQuery('#billing_postcode').is('select') && jQuery('#billing_city').val() && jQuery('#billing_state').val()) {
        scheduleLoad();
      }
    } catch(e){}
  });

  togglePJ();
  // Initial load (themes that prefill city/state without firing change events)
  setTimeout(function(){
    try {
      if (jQuery('#billing_city').val() && jQuery('#billing_state').val()) {
        scheduleLoad();
      }
    } catch(e){}
  }, 800);
});
