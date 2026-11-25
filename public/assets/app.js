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
        if (result.ok && form.dataset.reload !== 'false') setTimeout(() => location.reload(), 700);
      } catch (error) {
        if (output) output.textContent = 'Permintaan gagal: ' + error.message;
      } finally {
        if (button) { button.disabled = false; button.innerHTML = button.dataset.oldText; }
      }
    });
  });

  document.querySelectorAll('form[data-stream]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
      const button = form.querySelector('[type="submit"]');
      const output = document.querySelector(form.dataset.output || '[data-stream-output]');
      if (button) { button.disabled = true; button.dataset.oldText = button.innerHTML; button.textContent = 'Berjalan…'; }
      if (output) { output.textContent = ''; output.classList.remove('text-rose-300'); }
      try {
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.task = form.dataset.stream;
        const response = await fetch('/api/stream', {
          method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf}, body: JSON.stringify(payload)
        });
        if (!response.ok || !response.body) throw new Error('Server menolak proses (' + response.status + ').');
        if (!response.headers.get('content-type')?.includes('text/event-stream')) throw new Error('Response streaming bukan SSE.');
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
              if (output && typeof eventData.output === 'string') output.textContent += eventData.output;
              if (eventData.done === true) {
                streamExitCode = Number(eventData.exitCode);
                if (output) output.textContent += streamExitCode === 0 ? '\n[SELESAI:0]\n' : '\n[PROSES GAGAL:' + streamExitCode + ']\n';
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
        if (output) { output.textContent += '\n' + error.message; output.classList.add('text-rose-300'); }
      } finally {
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
  if (!document.querySelector('[data-dashboard]')) return;
  const byId = id => document.getElementById(id);
  const show = (id, value) => { byId(id).textContent = value; };
  const num = new Intl.NumberFormat('id-ID', {maximumFractionDigits:2});
  const fmt = (value, unit = '') => value == null ? '—' : num.format(value) + (unit ? ' ' + unit : '');
  const rate = bits => bits == null ? '—' : bits >= 1000000 ? fmt(bits / 1000000, 'Mbps') : fmt(bits / 1000, 'kbps');
  const charts = new Map();
  const units = {qps:'QPS', cache_hit_ratio:'%', recursion_avg_ms:'ms', cpu_percent:'%', memory_used_mb:'MiB', disk_used_gb:'GiB'};
  let payload;
  function plot(key, series, unit) {
    if (!window.Chart) return;
    const labels = payload.history.map(row => row.recorded_at.slice(11,19));
    const datasets = series.map(([label, data], i) => ({
      label, data, borderColor:i ? '#a5b4fc' : '#2dd4bf', pointRadius:1, borderWidth:2
    }));
    const formatter = key === 'network' ? rate : value => fmt(value, unit);
    if (!charts.has(key)) {
      charts.set(key,new Chart(byId('chart-' + key), {type:'line',data:{labels,datasets},options:{
        responsive:true, maintainAspectRatio:false, animation:false, interaction:{mode:'index',intersect:false},
        plugins:{legend:{display:key === 'network'}, tooltip:{callbacks:{label:item=>item.dataset.label + ' · ' + formatter(item.parsed.y)}}},
        scales:{x:{ticks:{maxTicksLimit:5}},y:{beginAtZero:true,ticks:{maxTicksLimit:4,callback:formatter}}}
      }}));
    } else {
      const chart = charts.get(key);
      chart.data = {labels,datasets};
      chart.update('none');
    }
  }
  function render() {
    const rows = payload.history;
    const latest = rows.at(-1) || {};
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
    select.replaceChildren(...names.map(n => new Option(n,n,false,n === selected)));
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
