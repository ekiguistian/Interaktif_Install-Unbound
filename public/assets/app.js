(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  document.querySelectorAll('form[data-api]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
      const button = form.querySelector('[type="submit"]');
      const output = form.querySelector('[data-result]') || document.querySelector('[data-page-result]');
      if (button) { button.disabled = true; button.dataset.oldText = button.innerHTML; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses'; }
      if (output) output.textContent = '';
      try {
        const data = Object.fromEntries(new FormData(form).entries());
        const response = await fetch('/api/action', {
          method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
          body: JSON.stringify({...data, action: form.dataset.api})
        });
        const result = await response.json();
        if (output) {
          if (form.dataset.api === 'config-view') output.textContent = result.config || result.message || '';
          else if (form.dataset.api === 'cron-view') output.textContent = result.output || result.message || '';
          else output.textContent = result.message || (result.ok ? 'Berhasil.' : 'Permintaan gagal.');
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
      let wizardCompleted = false;
      const isWizard = form.dataset.stream === 'wizard';
      const wizardButtonText = isWizard ? form.querySelector('[data-wizard-button]') : null;
      const wizardStepInput = isWizard ? form.querySelector('[data-wizard-step]') : null;
      const wizardTotalSteps = isWizard ? document.querySelectorAll('#wizard-steps li').length : 0;
      if (button) {
        button.disabled = true;
        if (isWizard) {
          if (wizardButtonText) wizardButtonText.textContent = 'Berjalan';
        } else {
          button.dataset.oldText = button.innerHTML;
          button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Berjalan';
        }
      }
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
        if (isWizard) {
          const currentStep = Number(wizardStepInput?.value);
          if (streamExitCode === 0 && Number.isInteger(currentStep) && currentStep >= 1 && currentStep <= wizardTotalSteps) {
            const nextStep = currentStep + 1;
            document.querySelectorAll('#wizard-steps li').forEach((item, index) => {
              if (index < currentStep) item.classList.add('border-emerald-400/40','text-emerald-200');
            });
            if (nextStep <= wizardTotalSteps) {
              wizardStepInput.value = String(nextStep);
              if (wizardButtonText) wizardButtonText.textContent = 'Jalankan tahap ' + nextStep;
            } else {
              wizardCompleted = true;
              if (wizardButtonText) wizardButtonText.textContent = 'Instalasi selesai';
            }
          } else if (wizardButtonText) {
            wizardButtonText.textContent = 'Ulangi tahap ' + (wizardStepInput?.value || '1');
          }
        }
      } catch (error) {
        if (output) { output.textContent += '\n' + error.message; output.classList.add('text-rose-300'); }
        if (isWizard && wizardButtonText) wizardButtonText.textContent = 'Ulangi tahap ' + (wizardStepInput?.value || '1');
      } finally {
        if (button) {
          button.disabled = wizardCompleted;
          if (!isWizard) button.innerHTML = button.dataset.oldText;
        }
      }
    });
  });

  const dashboard = document.querySelector('[data-dashboard]');
  if (!dashboard) return;
  const fmt = new Intl.NumberFormat('id-ID');
  const setText = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
  const statusClass = (value) => value === 'active' ? 'text-emerald-300' : value === 'inactive' ? 'text-amber-300' : 'text-slate-500';
  let qpsChart, trafficChart, cacheChart;

  function buildChart(elementId, title, labels, datasets) {
    const canvas = document.getElementById(elementId);
    if (!canvas || !window.Chart) return null;
    return new Chart(canvas, {
      type: 'line', data: {labels, datasets},
      options: {responsive:true, maintainAspectRatio:false, interaction:{intersect:false,mode:'index'},
        plugins:{legend:{labels:{color:'#cbd5e1'}},title:{display:false,text:title},tooltip:{mode:'index',intersect:false}},
        scales:{x:{ticks:{color:'#64748b',maxTicksLimit:8},grid:{color:'rgba(148,163,184,.08)'}},y:{beginAtZero:true,ticks:{color:'#64748b'},grid:{color:'rgba(148,163,184,.08)'}}}}
    });
  }

  async function refresh() {
    try {
      const response = await fetch('/api/metrics', {cache:'no-store'});
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Metrik belum tersedia');
      for (const name of ['unbound','zabbix']) {
        const el = document.getElementById('status-' + name);
        if (el) { el.textContent = payload.services[name] || 'unknown'; el.className = 'mt-2 text-xl font-semibold ' + statusClass(el.textContent); }
      }
      const action = document.getElementById('main-action');
      const actionText = document.getElementById('main-action-text');
      if (action && actionText) {
        const running = payload.services.unbound === 'active';
        action.href = running ? '/settings' : '/wizard';
        actionText.textContent = running ? 'Setting' : 'Install Wizard';
        action.querySelector('i').className = running ? 'fa-solid fa-sliders' : 'fa-solid fa-wand-magic-sparkles';
      }
      setText('unbound-version', payload.services.unbound_version ? 'Versi ' + payload.services.unbound_version : 'Versi belum tersedia');
      const latest = payload.latest || {};
      setText('metric-queries', fmt.format(latest.total_queries || 0));
      setText('metric-replies', fmt.format(latest.total_replies || 0));
      setText('metric-cache', Number(latest.cache_hit_ratio || 0).toLocaleString('id-ID',{maximumFractionDigits:2}) + '%');
      setText('metric-qps', Number(latest.qps || 0).toLocaleString('id-ID',{maximumFractionDigits:2}));
      const bandwidth = (bytesPerSecond) => {
        const bits = Number(bytesPerSecond || 0) * 8;
        return bits >= 1000000 ? (bits / 1000000).toLocaleString('id-ID',{maximumFractionDigits:2}) + ' Mbps' : (bits / 1000).toLocaleString('id-ID',{maximumFractionDigits:1}) + ' Kbps';
      };
      setText('metric-rx', bandwidth(latest.rx_bps));
      setText('metric-tx', bandwidth(latest.tx_bps));
      setText('metric-recursion', Number(latest.recursion_avg_ms || 0).toLocaleString('id-ID',{maximumFractionDigits:2}) + ' ms');
      setText('metric-cpu', Number(latest.cpu_percent || 0).toLocaleString('id-ID',{maximumFractionDigits:1}) + '%');
      setText('metric-load', [latest.load_1m,latest.load_5m,latest.load_15m].map((value) => Number(value || 0).toLocaleString('id-ID',{maximumFractionDigits:2})).join(' / '));
      setText('metric-memory', fmt.format(latest.memory_used_mb || 0) + ' / ' + fmt.format(latest.memory_total_mb || 0) + ' MB');
      setText('metric-disk', Number(latest.disk_used_gb || 0).toLocaleString('id-ID',{maximumFractionDigits:1}) + ' / ' + Number(latest.disk_total_gb || 0).toLocaleString('id-ID',{maximumFractionDigits:1}) + ' GB');
      const up = Number(latest.uptime_seconds || 0);
      setText('metric-uptime', Math.floor(up / 86400) + ' hari ' + Math.floor((up % 86400) / 3600) + ' jam');
      const breakdown = (data) => Object.entries(data || {}).sort((a,b) => b[1]-a[1]).slice(0,8).map(([name,value]) => name + ': ' + fmt.format(value)).join(' · ') || 'Belum ada kueri';
      setText('query-types', breakdown(latest.query_types));
      setText('response-codes', breakdown(latest.response_codes));
      setText('updated-at', latest.recorded_at || 'Belum ada data worker');
      const labels = payload.history.map(row => row.recorded_at?.slice(11,16) || '');
      const qps = payload.history.map(row => Number(row.qps || 0));
      const rx = payload.history.map(row => Number(row.rx_bps || 0) * 8 / 1000000);
      const tx = payload.history.map(row => Number(row.tx_bps || 0) * 8 / 1000000);
      const cache = payload.history.map(row => Number(row.cache_hit_ratio || 0));
      if (!qpsChart) qpsChart = buildChart('qps-chart','QPS',labels,[{label:'QPS',data:qps,borderColor:'#22d3ee',backgroundColor:'rgba(34,211,238,.12)',fill:true,tension:.3,pointRadius:1}]);
      else {qpsChart.data.labels=labels;qpsChart.data.datasets[0].data=qps;qpsChart.update('none');}
      if (!trafficChart) trafficChart = buildChart('traffic-chart','Traffic',labels,[{label:'RX Mbps',data:rx,borderColor:'#34d399',tension:.3,pointRadius:1},{label:'TX Mbps',data:tx,borderColor:'#818cf8',tension:.3,pointRadius:1}]);
      else {trafficChart.data.labels=labels;trafficChart.data.datasets[0].data=rx;trafficChart.data.datasets[1].data=tx;trafficChart.update('none');}
      if (!cacheChart) cacheChart = buildChart('cache-chart','Cache Hit',labels,[{label:'Cache Hit %',data:cache,borderColor:'#fbbf24',backgroundColor:'rgba(251,191,36,.1)',fill:true,tension:.3,pointRadius:1}]);
      else {cacheChart.data.labels=labels;cacheChart.data.datasets[0].data=cache;cacheChart.update('none');}
    } catch (error) {
      setText('updated-at', 'Metrik belum tersedia: ' + error.message);
    }
  }
  refresh();
  window.setInterval(refresh, 10000);
})();
