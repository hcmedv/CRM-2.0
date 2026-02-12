(function () {
  'use strict';

  const NS = window.CRM_EVENTS;
  NS.api = NS.api || {};

  async function fetchJson(url)
  {
    const r = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const txt = await r.text();
    let j = null;
    try { j = JSON.parse(txt); } catch(e) {}

    if (!r.ok || !j) {
      throw new Error('API Error');
    }

    return j;
  }

  NS.api.listEvents = async function ()
  {
    return await fetchJson('/api/crm/api_crm_events_list_events.php');
  };

})();
