(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  document.querySelectorAll('form[data-api]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
      const button = form.querySelector('[type="submit"]');
      const output = form.querySelector('[data-result]') || document.querySelector(form.dataset.output || '[data-page-result]');
      if (button) { button.disabled = true; button.dataset.oldText = button.innerHTML; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses'; }
      if (output) output.textContent = '';
      try {
        const data = Object.fromEntries(new FormData(form).entries());
        const response = await fetch('/api/action', {
          method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
          body: JSON.stringify({...data, action: form.dataset.api})
        });
        const body = await response.text();
        let result;
        try { result = JSON.parse(body); }
        catch (_) { throw new Error('Server tidak mengirim hasil (HTTP ' + response.status + '). Muat ulang halaman dan cek status sebelum mengulang.'); }
        if (output) {
          output.textContent = result.message || (result.ok ? 'Berhasil.' : 'Permintaan gagal.');
        }
        if (result.ok && form.dataset.logToggle && result.logging?.option === form.elements.option.value) {
          const enabled = result.logging.enabled === true;
          const label = (enabled ? 'Nonaktifkan ' : 'Aktifkan ') + form.dataset.logToggle;
          const status = form.querySelector('[data-log-state]');
          form.elements.value.value = enabled ? 'no' : 'yes';
          status.textContent = enabled ? 'Aktif' : 'Nonaktif';
          status.dataset.active = String(enabled);
          button.dataset.oldText = label;
        }
        if (result.ok && form.dataset.reload !== 'false') setTimeout(() => location.reload(), 700);
      } catch (error) {
        if (output) output.textContent = 'Permintaan gagal: ' + error.message;
      } finally {
        if (button) { button.disabled = false; button.innerHTML = button.dataset.oldText; }
      }
    });
  });

  document.querySelectorAll('form[data-stream]').forEach((form) => {
    const stop = form.querySelector('[data-stream-stop]');
    const logStatus = stop ? document.getElementById('unbound-log-status') : null;
    let controller;
    stop?.addEventListener('click', () => controller?.abort());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (controller) return;
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
      const button = form.querySelector('[type="submit"]');
      const output = document.querySelector(form.dataset.output || '[data-stream-output]');
      if (button) { button.disabled = true; button.dataset.oldText = button.innerHTML; button.textContent = 'Berjalan…'; }
      if (output) { output.textContent = ''; output.classList.remove('text-rose-300'); }
      if (stop) {
        controller = new AbortController();
        stop.disabled = false;
        logStatus.textContent = 'Menghubungkan…';
      }
      try {
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.task = form.dataset.stream;
        const response = await fetch('/api/stream', {
          method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
          body: JSON.stringify(payload), signal: controller?.signal
        });
        if (!response.ok || !response.body) throw new Error('Server menolak proses (' + response.status + ').');
        if (!response.headers.get('content-type')?.includes('text/event-stream')) throw new Error('Response streaming bukan SSE.');
        if (logStatus) logStatus.textContent = 'Menampilkan log langsung.';
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let streamExitCode = null;
        while (true) {
          const {value, done} = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, {stream:true});
          let boundary;
          while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);
            const data = frame.split(/\r?\n/).filter((line) => line.startsWith('data:')).map((line) => line.slice(5).trim()).join('\n');
            if (!data) continue;
            try {
              const eventData = JSON.parse(data);
              if (output && typeof eventData.output === 'string') {
                const combined = output.textContent + eventData.output;
                output.textContent = stop && combined.length > 64000 ? combined.slice(-64000) : combined;
              }
              if (eventData.done === true) {
                streamExitCode = Number(eventData.exitCode);
                if (logStatus) logStatus.textContent = streamExitCode === 0 ? 'Live Log berakhir.' : 'Live Log gagal. Lihat pesan di bawah.';
                else if (output) output.textContent += streamExitCode === 0 ? '\n[SELESAI:0]\n' : '\n[PROSES GAGAL:' + streamExitCode + ']\n';
              }
              if (output) output.scrollTop = output.scrollHeight;
            } catch (_) {
              if (output) output.textContent += data;
            }
          }
        }
        if (streamExitCode === null || !Number.isInteger(streamExitCode)) {
          throw new Error('Streaming berakhir tanpa status akhir dari server.');
        }
        if (output && /\[PROSES GAGAL:/.test(output.textContent)) output.classList.add('text-rose-300');
      } catch (error) {
        if (controller?.signal.aborted) {
          logStatus.textContent = 'Live Log dihentikan.';
        } else {
          if (logStatus) logStatus.textContent = 'Live Log terputus.';
          if (output) { output.textContent += '\n' + error.message; output.classList.add('text-rose-300'); }
        }
      } finally {
        controller = undefined;
        if (stop) stop.disabled = true;
        if (button) {
          button.disabled = false;
          button.innerHTML = button.dataset.oldText;
        }
      }
    });
  });

  for (const select of document.querySelectorAll('select[name="protocol"]')) {
    const port = select.form.elements.port;
    const toggle = () => { port.disabled = !['tcp','udp','tcp-udp'].includes(select.value); };
    select.addEventListener('change', toggle);
    toggle();
  }
  const updatePanel = document.querySelector('[data-update-panel]');
  if (updatePanel) {
    const check = updatePanel.querySelector('[data-update-check]');
    const install = updatePanel.querySelector('[data-update-run]');
    const message = updatePanel.querySelector('[data-update-message]');
    let busy = false;
    let commit = '';
    let timer;
    async function requestUpdate(action) {
      clearTimeout(timer);
      check.disabled = install.disabled = true;
      try {
        const response = await fetch('/api/action', {
          method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:JSON.stringify({action,commit}), signal:AbortSignal.timeout(36000)
        });
        if (response.status === 401) { location.assign('/login?next=/settings/update'); return; }
        const data = await response.json();
        if (!data.ok) throw new Error(data.message || 'Gagal membaca status pembaruan.');
        const state = data.state;
        commit = state.commit || '';
        busy = ['queued','cloning','installing'].includes(state.phase);
        updatePanel.querySelector('[data-installed]').textContent = state.installed || 'Belum tercatat';
        updatePanel.querySelector('[data-installed-version]').textContent = state.installed_version || 'Belum tercatat';
        updatePanel.querySelector('[data-update-version]').textContent = state.version || '—';
        updatePanel.querySelector('[data-update-commit]').textContent = commit || '—';
        message.textContent = state.message || 'Belum ada pengecekan.';
        install.hidden = state.phase !== 'available';
      } catch (error) {
        message.textContent = busy ? 'Menunggu panel kembali setelah pemasangan…' : error.message;
      } finally {
        check.disabled = install.disabled = busy;
        if (busy) timer = setTimeout(() => requestUpdate('update-status'), 3000);
      }
    }
    check.addEventListener('click', () => requestUpdate('update-check'));
    install.addEventListener('click', () => {
      if (window.confirm('Pasang commit yang baru dicek dari GitHub main?')) requestUpdate('update-run');
    });
    requestUpdate('update-status');
  }
  if (!document.querySelector('[data-dashboard]')) return;
  const byId = id => document.getElementById(id);
  const show = (id, value) => { byId(id).textContent = value; };
  const num = new Intl.NumberFormat('id-ID', {maximumFractionDigits:2});
  const fmt = (value, unit = '') => value == null ? '—' : num.format(value) + (unit ? ' ' + unit : '');
  const rate = bits => bits == null ? '—' : bits >= 1000000 ? fmt(bits / 1000000, 'Mbps') : fmt(bits / 1000, 'kbps');
  const charts = new Map();
  const units = {qps:'QPS', cache_hit_ratio:'%', recursion_avg_ms:'ms', cpu_percent:'%', memory_used_mb:'MiB', disk_used_gb:'GiB'};
  let payload;
  let colors = chartColors();
  let themeRevision = 0;
  let serverOffset = 0;
  function chartColors() {
    const style = getComputedStyle(document.documentElement);
    return Object.fromEntries(['chart-a','chart-b','chart-grid','muted','surface','ink','line']
      .map(key => [key, style.getPropertyValue('--' + key).trim()]));
  }
  function timeLabel(seconds) {
    return new Date(seconds * 1000 + serverOffset).toISOString().slice(11,19);
  }
  function plot(key, series, unit) {
    if (!window.Chart) return;
    const datasets = series.map(([label, data], i) => ({
      label, data:data.map((y, index) => ({ x:Number(payload.history[index].epoch), y })),
      borderColor:colors[i ? 'chart-b' : 'chart-a'], pointRadius:0, pointHoverRadius:3, borderWidth:2, spanGaps:90
    }));
    const formatter = key === 'network' ? rate : value => fmt(value, unit);
    const data = {datasets};
    const chart = charts.get(key);
    const dataNeedsTheme = !chart || chart.themeRevision !== themeRevision;
    const options = dataNeedsTheme ? {
      responsive:true, maintainAspectRatio:false, animation:false, interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:key === 'network',labels:{color:colors.ink}},tooltip:{
        backgroundColor:colors.surface, titleColor:colors.ink, bodyColor:colors.ink,
        borderColor:colors.line, borderWidth:1,
        callbacks:{title:items=>timeLabel(items[0].parsed.x),label:item=>item.dataset.label + ' · ' + formatter(item.parsed.y)}
      }},
      scales:{
        x:{type:'linear',min:payload.now - 600,max:payload.now,
          ticks:{color:colors.muted,maxTicksLimit:5,callback:timeLabel},grid:{color:colors['chart-grid']}},
        y:{beginAtZero:true,ticks:{color:colors.muted,maxTicksLimit:4,callback:formatter},grid:{color:colors['chart-grid']}}
      }
    } : null;
    if (!chart) {
      const created = new Chart(byId('chart-' + key), {type:'line',data,options});
      created.themeRevision = themeRevision;
      charts.set(key, created);
    } else {
      chart.data = data;
      if (dataNeedsTheme) {
        chart.options = options;
        chart.themeRevision = themeRevision;
      }
      chart.options.scales.x.min = payload.now - 600;
      chart.options.scales.x.max = payload.now;
      chart.update('none');
    }
  }
  function render() {
    if (!payload) return;
    const rows = payload.history;
    const latest = rows.at(-1) || {};
    serverOffset = latest.recorded_at ? Date.parse(latest.recorded_at.replace(' ', 'T') + 'Z') - Number(latest.epoch) * 1000 : 0;
    show('host', payload.host);
    show('system-info', payload.os + ' · kernel ' + payload.kernel);
    show('service', [payload.services.unbound, payload.services.unbound_version, latest.epoch && latest.total_queries == null ? 'statistik belum tersedia' : ''].filter(Boolean).join(' · '));
    show('sample', latest.epoch ? latest.recorded_at + (payload.now - latest.epoch > 5 ? ' · data terlambat' : '') : 'Belum ada sampel');
    for (const [key,unit] of Object.entries(units)) {
      const total = key === 'memory_used_mb' ? latest.memory_total_mb : key === 'disk_used_gb' ? latest.disk_total_gb : null;
      show('value-' + key, total == null ? fmt(latest[key], unit) : fmt(latest[key]) + ' / ' + fmt(total,unit));
      plot(key, [[unit,rows.map(row=>row[key] == null ? null : Number(row[key]))]], unit);
    }
    const select = byId('interface');
    const names = Object.keys(latest.network || {});
    const selected = names.includes(select.value) ? select.value : names.includes(payload.interface) ? payload.interface : names.find(n => n !== 'lo') || names[0];
    if (names.join('\0') !== [...select.options].map(option=>option.value).join('\0')) {
      select.replaceChildren(...names.map(n => new Option(n,n)));
    }
    if (selected) select.value = selected;
    const traffic = latest.network?.[selected] || {};
    show('value-network', 'RX ' + rate(traffic.rx) + ' · TX ' + rate(traffic.tx));
    plot('network',['rx','tx'].map(key=>[key.toUpperCase(),rows.map(row=>row.network[selected]?.[key] ?? null)]),'');
    show('detail-queries',fmt(latest.total_queries));
    show('detail-replies',fmt(latest.total_replies));
    show('detail-load',['load_1m','load_5m','load_15m'].map(k=>fmt(latest[k])).join(' / '));
    show('detail-uptime', latest.uptime_seconds == null ? '—' : Math.floor(latest.uptime_seconds/86400) + ' hari ' + Math.floor(latest.uptime_seconds%86400/3600) + ' jam');
    for (const [id, data] of [['types',latest.query_types], ['codes',latest.response_codes]]) {
      show('detail-' + id, latest.total_queries == null ? '—' : Object.entries(data || {}).map(([name,value])=>name+' '+fmt(value)).join(' · ') || '—');
    }
  }
  byId('interface').addEventListener('change',render);
  document.addEventListener('themechange', () => {
    colors = chartColors();
    themeRevision++;
    render();
  });
  async function refresh() {
    const started = performance.now();
    try {
      const response = await fetch('/api/metrics',{cache:'no-store', signal:AbortSignal.timeout(5000)});
      if (response.status === 401) { location.assign('/login'); return; }
      const data = await response.json();
      if (!response.ok) throw new Error(data.message);
      payload = data;
      render();
    } catch (error) { show('sample',error.message); }
    setTimeout(refresh,Math.max(0,1000-(performance.now()-started)));
  }
  refresh();
})();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
