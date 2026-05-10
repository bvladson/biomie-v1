const ClientDossier = (function () {
  'use strict';

  let drawerEl = null;
  const cache = {};

  function init() {
    drawerEl = document.getElementById('clientDossierDrawer');
    if (!drawerEl) {
      drawerEl = document.createElement('div');
      drawerEl.id = 'clientDossierDrawer';
      drawerEl.className = 'mag-drawer hidden';
      document.body.appendChild(drawerEl);
    }

    const overlay = document.getElementById('overlay');
    if (overlay) {
      overlay.addEventListener('click', close);
    }
  }

  function renderLoading() {
    drawerEl.innerHTML = `
      <div class="drawer-header">
        <div class="drawer-titles">
          <h3>Dossie Comercial</h3>
          <span class="skeleton-line sm" style="width:120px; display:inline-block"></span>
        </div>
        <button class="btn-icon" onclick="ClientDossier.close()"><span class="material-symbols-rounded">close</span></button>
      </div>
      <div class="drawer-body">
        <div class="module-loading-grid cols-2">
          <span class="skeleton-block" style="height:80px"></span>
          <span class="skeleton-block" style="height:80px"></span>
        </div>
        <div class="module-loading-grid cols-1">
          <span class="skeleton-block" style="height:200px"></span>
        </div>
      </div>
    `;
  }

  function renderError(clientId, reason) {
    drawerEl.innerHTML = `
      <div class="drawer-header">
        <div class="drawer-titles">
          <h3>Dossie Comercial</h3>
        </div>
        <button class="btn-icon" onclick="ClientDossier.close()"><span class="material-symbols-rounded">close</span></button>
      </div>
      <div class="drawer-body">
        <div class="module-alert module-alert--info">
          <strong>Processamento assincrono</strong>
          <span>O cliente #${clientId} ainda nao esta pronto na camada de perfilamento ou nao foi localizado. (${reason})</span>
        </div>
        <p style="margin-top:20px; color:var(--text-dim);">Assim que a camada geo concluir a hidratacao, o dossie detalhado ficara disponivel.</p>
      </div>
    `;
  }

  function formatMoney(value) {
    if (value == null) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  }

  function maskDocument(doc) {
    const raw = String(doc || '');
    if (!raw) return 'N/A';
    const digits = raw.replace(/\D/g, '');
    if (digits.length === 11) return `***.***.${digits.substring(6, 9)}-**`;
    if (digits.length === 14) return `${digits.substring(0, 2)}.${digits.substring(2, 5)}.***/****-${digits.substring(12, 14)}`;
    return raw;
  }

  function normalizePayload(data) {
    const stats = data?.stats || {};
    const totalRevenue = Number(stats.ltv || 0);
    const totalNotes = Number(stats.nfs || 0);

    return {
      name: data?.name || 'Cliente sem nome',
      profileType: data?.type || 'N/D',
      document: data?.doc || '',
      segment: data?.segment || 'Nao classificado',
      nature: data?.nature || '-',
      geography: data?.geography || {},
      stats: {
        totalRevenueLifetime: totalRevenue,
        totalNotesLifetime: totalNotes,
        avgTicketLifetime: totalNotes > 0 ? totalRevenue / totalNotes : 0,
      },
    };
  }

  function renderProfile(data) {
    const profile = normalizePayload(data);
    const geo = profile.geography || {};

    drawerEl.innerHTML = `
      <div class="drawer-header" style="align-items:flex-start;">
        <div class="drawer-titles">
          <div class="topbar-badge" style="box-shadow:none; padding:4px 8px; font-size:10px; margin-bottom:6px; display:inline-flex;">${profile.profileType}</div>
          <h3>${profile.name}</h3>
          <small>Documento: ${maskDocument(profile.document)}</small>
        </div>
        <button class="btn-icon" onclick="ClientDossier.close()"><span class="material-symbols-rounded">close</span></button>
      </div>
      <div class="drawer-body" style="padding-top:10px;">
        <div class="module-alert module-alert--success" style="margin-bottom:20px;">
          <strong>Cliente consolidado</strong>
          <span>Receita ativa: ${formatMoney(profile.stats.totalRevenueLifetime)} em ${profile.stats.totalNotesLifetime} notas fiscais.</span>
        </div>

        <h4 style="margin-bottom:8px; display:flex; align-items:center; gap:4px;"><span class="material-symbols-rounded">location_city</span>Perfil e Demografia</h4>
        <div class="wallboard-card-body compact" style="margin-bottom:24px; border:1px solid var(--page-border); border-radius:var(--radius-md);">
          <table class="mag-table">
            <tbody>
              <tr><td style="color:var(--text-dim)">Segmento</td><td><strong>${profile.segment}</strong></td></tr>
              <tr><td style="color:var(--text-dim)">Natureza</td><td>${profile.nature || '-'}</td></tr>
              <tr><td style="color:var(--text-dim)">Estado / Cidade</td><td>${geo.uf || '-'} / ${geo.city || '-'}</td></tr>
              <tr><td style="color:var(--text-dim)">Bairro</td><td>${geo.neighborhood || '-'}</td></tr>
              <tr><td style="color:var(--text-dim)">CEP</td><td>${geo.cep || '-'}</td></tr>
            </tbody>
          </table>
        </div>

        <h4 style="margin-bottom:8px; display:flex; align-items:center; gap:4px;"><span class="material-symbols-rounded">paid</span>Recorrencia</h4>
        <div class="module-tiny-kpis" style="margin-bottom:24px;">
          <div class="mini"><span>Ticket medio</span><strong>${formatMoney(profile.stats.avgTicketLifetime)}</strong></div>
          <div class="mini"><span>Notas</span><strong>${profile.stats.totalNotesLifetime}</strong></div>
        </div>
      </div>
    `;
  }

  async function fetchProfile(clientId) {
    if (typeof window.fetchJson === 'function') {
      return window.fetchJson(`api/client_profile.php?cliente_id=${clientId}`, { timeoutMs: 30000 });
    }

    const response = await fetch(`api/client_profile.php?cliente_id=${clientId}`, {
      credentials: 'same-origin',
      cache: 'no-store',
    });
    return response.json();
  }

  async function open(clientId) {
    if (window.BIomieFeatureFlags?.client_dossier_enabled === false) {
      return;
    }

    if (!drawerEl) init();

    drawerEl.classList.remove('hidden');
    drawerEl.classList.add('visible');
    document.getElementById('overlay')?.classList.add('visible');

    if (cache[clientId]) {
      renderProfile(cache[clientId]);
      return;
    }

    renderLoading();

    try {
      const payload = await fetchProfile(clientId);
      if (!payload?.ok) {
        renderError(clientId, payload?.message || 'Soft-404');
        return;
      }

      cache[clientId] = payload.data;
      renderProfile(payload.data);
    } catch (error) {
      renderError(clientId, error?.message || 'Falha ao carregar o dossie.');
    }
  }

  function close() {
    if (drawerEl) {
      drawerEl.classList.remove('visible');
      drawerEl.classList.add('hidden');
    }
    document.getElementById('overlay')?.classList.remove('visible');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { open, close };
})();

window.openClientDossier = function (clientId) {
  return ClientDossier.open(clientId);
};

const style = document.createElement('style');
style.innerHTML = `
.mag-drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: 400px;
  max-width: 100vw;
  background: var(--page-surface);
  box-shadow: -4px 0 24px rgba(0,0,0,0.2);
  z-index: 1000;
  transform: translateX(100%);
  transition: transform 0.3s cubic-bezier(0.1, 0, 0.1, 1);
  display: flex;
  flex-direction: column;
}
.mag-drawer.visible { transform: translateX(0); }
.mag-drawer .drawer-header {
  padding: 24px;
  border-bottom: 1px solid var(--page-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.mag-drawer .drawer-titles h3 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--text-main);
}
.mag-drawer .drawer-titles small { color: var(--text-dim); }
.mag-drawer .drawer-body {
  padding: 24px;
  flex: 1;
  overflow-y: auto;
}
`;
document.head.appendChild(style);
