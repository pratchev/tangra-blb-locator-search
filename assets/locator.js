(function($){
  const state = {
    page: 1,
    perPage: TG_BLBL.perPage || 10,
    sort: 'brand,franchisee_name',
    brand: '',
    country: '',
    franchisee: '',
    city: '',
    state: '',
    zip: '',
    distance: '25', // miles
    from: 'current',
    nearbyCity: '',
    userLoc: null
  };

  function setLoading(on){
    const $wrap = $('.tgfg-wrap');
    if(on){ $wrap.addClass('is-loading'); }
    else  { $wrap.removeClass('is-loading'); }
  }

  function formatAddress(r){
    const parts = [r.Address, r.City, r.State, r.ZIP, r.Country].filter(Boolean);
    return parts.join(', ');
  }
  function telHref(p){ return 'tel:' + (p||'').replace(/[^0-9+]/g,''); }
  function mailHref(e){ return 'mailto:' + e; }
  function mapHref(addr){ return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr); }

  function render(rows, total){
    $('#tgfg-results-meta').text(total + ' locations found');
    const $c = $('#tgfg-results').empty();
    rows.forEach(r => {
      const addr = formatAddress(r);
      const $card = $('<div class="tgfg-card"/>');

      $card.append($('<div class="tgfg-line brand"/>').text(r.Brand));                // brand in BLB blue
      $card.append(
        $('<div class="tgfg-line"/>')
            .append('<span class="icon icon-franchisee"></span>')
            .append($('<span/>').text(r['Franchisee Name']))
        );        // franchisee name

 //     if(addr){
 //       $card.append(
 //         $('<div class="tgfg-line"/>')
 //           .append('<span class="icon icon-location"></span>')
 //           .append($('<a target="_blank"/>').attr('href', mapHref(addr)).text(addr))
 //       );
 //     }

  // Build multiline address: 
  // Line 1: City, State (no ZIP)
  // Line 2: Country
  (function(){
    const addr1   = (r.Address || '').trim();
    const city    = (r.City || '').trim();
    const state   = (r.State || '').trim();
    const zip     = (r.ZIP || '').trim();
    const country = (r.Country || '').trim();

    // City, State composition (no ZIP)
    const line1Parts = [];
    if (city) line1Parts.push(city);
    if (state) line1Parts.push(state);
    const line1 = line1Parts.join(', ');

    const lines = [];
    if (line1)   lines.push(line1);
    if (country) lines.push(country);

    // Only render if at least one line exists
    if (lines.length) {
      const mapTarget = [addr1, line1, country].filter(Boolean).join(', ');
      const $lines = $('<div class="tgfg-address-lines"/>');
      lines.forEach(l => $lines.append($('<div class="row"/>').text(l)));

      $card.append(
        $('<div class="tgfg-line address"/>')
          .append('<span class="icon icon-location"></span>')
          .append(
            $('<a target="_blank" class="tgfg-address-link"/>')
              .attr('href', mapHref(mapTarget))
              .append($lines)
          )
      );
    }
  })();

      if(r.Phone){
        $card.append(
          $('<div class="tgfg-line"/>')
            .append('<span class="icon icon-phone"></span>')
            .append($('<a/>').attr('href', telHref(r.Phone)).text(r.Phone))
        );
      }
      if(r.Email){
        $card.append(
          $('<div class="tgfg-line"/>')
            .append('<span class="icon icon-email"></span>')
            .append($('<a/>').attr('href', mailHref(r.Email)).text(r.Email))
        );
      }
      if(r.Distance){
        $card.append(
          $('<div class="tgfg-line"/>')
          .append('<span class="icon icon-distance"></span>')
          .append($('<span/>').text(r.Distance))
      );
      }
      $c.append($card);
    });
  }

  function pager(total){
    const pages = Math.max(1, Math.ceil(total / state.perPage));
    const $p = $('#tgfg-pager').empty();

    const btn = (label, disabled, onClick) => {
      const $b = $('<button type="button" class="pg-btn"/>').text(label);
      if(disabled) $b.attr('disabled', 'disabled');
      if(onClick) $b.on('click', onClick);
      return $b;
    };

    $p.append(btn('⏮ First', state.page===1, ()=>{ state.page=1; search(); }));
    $p.append(btn('◀ Prev',  state.page===1, ()=>{ state.page=Math.max(1, state.page-1); search(); }));
    $p.append($('<strong/>').text(' Page ' + state.page + ' of ' + pages + ' '));

    // compact, numbers-only dropdown directly after the page text
    const $sel = $('<select class="pg-select" aria-label="Go to page"></select>');
    for(let i=1;i<=pages;i++){ $sel.append($('<option/>').val(i).text(i)); }
    $sel.val(state.page).on('change', function(){ state.page = parseInt($(this).val(), 10) || 1; search(); });
    $p.append($sel);

    $p.append(btn('Next ▶', state.page===pages, ()=>{ state.page=Math.min(pages, state.page+1); search(); }));
    $p.append(btn('Last ⏭', state.page===pages, ()=>{ state.page=pages; search(); }));
  }

  function search(){
    const data = new FormData();
    data.append('action', 'tg_blbls_search');
    data.append('nonce', TG_BLBL.nonce);
    ['brand','country','franchisee','city','state','zip','sort','distance','from','nearby_city'].forEach(k => {
      const val = k === 'nearby_city' ? state.nearbyCity : (k === 'from' ? state.from : state[k]);
      data.append(k, val || '');
    });
    if(state.userLoc){ data.append('userLat', state.userLoc.lat); data.append('userLng', state.userLoc.lng); }
    data.append('page', state.page);
    data.append('perPage', state.perPage);

    setLoading(true); 

    fetch(TG_BLBL.ajax, { method: 'POST', body: data })
      .then(r=>r.json()).then(j=>{
        if(!j.success) return;
        render(j.data.rows, j.data.total);
        pager(j.data.total);

        const $b = $('#tgfg-brand'), $c = $('#tgfg-country'), $s = $('#tgfg-state');
        if($b.data('loaded')!=='1'){
          j.data.brands.forEach(v => $b.append($('<option/>').val(v).text(v)));
          $b.data('loaded','1');
        }
        if($c.data('loaded')!=='1'){
          j.data.countries.forEach(v => $c.append($('<option/>').val(v).text(v)));
          $c.data('loaded','1');
        }
        if($s.data('loaded')!=='1'){
          j.data.states.forEach(v => $s.append($('<option/>').val(v).text(v)));
          $s.data('loaded','1');
        }
      })
      .catch(()=>{/* optionally show a toast */})
      .finally(()=> setLoading(false)); ;
  }

  function bind(){
    $('#tgfg-form').on('submit', function(e){
      e.preventDefault();
      const f = new FormData(this);
      state.brand      = f.get('brand')      || '';
      state.country    = f.get('country')    || '';
      state.franchisee = f.get('franchisee') || '';
      state.city       = f.get('city')       || '';
      state.state      = f.get('state')      || '';
      // Clean ZIP input to remove non-numeric characters except dashes
      const zipRaw     = f.get('zip')        || '';
      state.zip        = zipRaw.replace(/[^0-9\-]/g, '');
      state.distance   = f.get('distance')   || '';
      state.from       = f.get('from')       || 'current';
      state.nearbyCity = f.get('nearby_city') || '';
      state.sort       = f.get('sort')       || 'brand,franchisee_name';
      state.page       = 1;

      // Only get geolocation if using current location and distance/sort requires it
      if(state.from === 'current' && (state.distance || state.sort==='distance') && navigator.geolocation){
        navigator.geolocation.getCurrentPosition(function(pos){
          state.userLoc = {lat: pos.coords.latitude, lng: pos.coords.longitude};
          search();
        }, function(){ search(); }, {enableHighAccuracy:true, timeout:5000, maximumAge:60000});
      } else {
        search();
      }
    });
    
    // Add real-time ZIP input formatting
    $('#tgfg-form input[name="zip"]').on('input', function(){
      const val = this.value.replace(/[^0-9\-]/g, '');
      if(val !== this.value) {
        this.value = val;
      }
    });
    
    $('#tgfg-reset').on('click', function(){
      $('#tgfg-form')[0].reset();
      state.brand=state.country=state.franchisee=state.city=state.state=state.zip='';
      state.distance='25';
      state.from='current'; state.nearbyCity='';
      state.userLoc=null; state.sort='distance'; state.page=1;
      $('input[name="distance"]').val('25');
      search();
    });
  }

  $(function(){ bind(); search(); });
})(jQuery);
