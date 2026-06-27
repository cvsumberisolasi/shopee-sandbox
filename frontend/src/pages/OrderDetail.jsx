import { useState, useEffect } from 'react';
import { SHOP_ID } from '../config.js';

export default function OrderDetail({ orderSn, onBack }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [labelState, setLabelState] = useState({ status: 'idle', url: null, message: null });

  useEffect(() => {
    if (!orderSn) return;
    fetch(`/api/orders/${orderSn}/detail`)
      .then(r => r.json())
      .then(d => { if (d.error) throw new Error(d.message || d.error); setData(d); })
      .catch(e => setError(e.message));
  }, [orderSn]);

  if (!orderSn) return null;
  if (error) return (
    <div className="panel">
      <button onClick={onBack} className="btn-back">← Kembali ke Orders</button>
      <div className="error-banner">{error}</div>
    </div>
  );
  if (!data) return <div className="panel empty">Loading...</div>;

  const d = data.detail || {};
  const addr = d.recipient_address || {};
  const items = d.item_list || [];
  const packages = d.package_list || [];

  function fmtTime(ts) {
    return ts ? new Date(ts * 1000).toLocaleString('id-ID', {
      day: '2-digit', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    }) : '—';
  }
  function fmtDate(ts) {
    return ts ? new Date(ts * 1000).toLocaleDateString('id-ID', {
      day: '2-digit', month: 'short', year: 'numeric'
    }) : '—';
  }
  function fmtAmount(amt, cur) {
    if (amt == null) return '—';
    return `${(amt).toLocaleString('id-ID')} ${cur || ''}`;
  }

  async function fetchLabel() {
    setLabelState({ status: 'loading', url: null, message: 'Meminta dokumen ke Shopee...' });
    try {
      const res = await fetch(`/api/orders/${orderSn}/label?shop_id=${SHOP_ID}`, { method: 'POST' });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || data.error || `HTTP ${res.status}`);
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      setLabelState({ status: 'ready', url, message: 'PDF siap' });
      const win = window.open(url, '_blank');
      if (win) win.focus();
    } catch (e) {
      setLabelState({ status: 'error', url: null, message: e.message });
    }
  }

  return (
    <div className="panel order-detail">
      <div className="detail-header">
        <button onClick={onBack} className="btn-back">← Kembali</button>
        <div className="detail-title">
          <h2>Order {data.order_sn}</h2>
          <span className={`status ${d.order_status || ''}`}>{d.order_status || data.summary.status || '—'}</span>
        </div>
      </div>

      {labelState.status !== 'idle' && (
        <div className={`label-status ${labelState.status}`}>
          {labelState.status === 'ready' && (
            <>✅ Resi siap — <a href={labelState.url} target="_blank" rel="noreferrer">Download PDF</a></>
          )}
          {labelState.status === 'error' && <>❌ {labelState.message}</>}
          {labelState.status === 'loading' && <>⏳ {labelState.message}</>}
        </div>
      )}

      <div className="detail-layout">
        {/* LEFT COLUMN */}
        <div className="detail-left">
          <SectionCard title="Alamat Pengiriman" icon="📍">
            <div className="addr-name">{addr.name || '—'}</div>
            <div className="addr-phone">{addr.phone || '—'}</div>
            <div className="addr-line">{addr.full_address || '—'}</div>
            <div className="addr-line muted">{[addr.city, addr.state, addr.zipcode].filter(Boolean).join(', ')}</div>
          </SectionCard>

          <SectionCard title={`Produk (${items.length})`} icon="📦">
            {items.length === 0 ? <div className="muted">Tidak ada produk</div> : items.map((it, i) => (
              <div key={i} className="product-row">
                <div className="product-img">
                  {it.image_info?.image_url
                    ? <img src={it.image_info.image_url} alt="" />
                    : <div className="img-placeholder">📦</div>}
                </div>
                <div className="product-info">
                  <div className="product-name">{it.item_name}</div>
                  {it.model_name && <div className="muted small">Variasi: {it.model_name}</div>}
                  <div className="muted small">SKU: {it.item_sku || it.item_id}</div>
                  <div className="product-qty">x{it.model_quantity_purchased ?? 1}</div>
                </div>
                <div className="product-price">
                  {fmtAmount(it.model_discounted_price, d.currency)}
                </div>
              </div>
            ))}
          </SectionCard>

          <SectionCard title={`Paket (${packages.length})`} icon="🚚">
            {packages.length === 0 ? <div className="muted">Belum ada paket</div> : packages.map((pkg, i) => (
              <div key={i} className="package-row">
                <div>
                  <div className="package-label">Paket #{i + 1}</div>
                  <div className="package-carrier">{pkg.shipping_carrier || d.shipping_carrier || '—'}</div>
                  <div className="muted small">Resi: {pkg.package_number || '—'}</div>
                  {pkg.tracking_number && <div className="muted small">Tracking: {pkg.tracking_number}</div>}
                </div>
                <span className={`status ${pkg.logistics_status || ''}`}>{pkg.logistics_status || '—'}</span>
              </div>
            ))}
          </SectionCard>

          {d.note && (
            <SectionCard title="Catatan dari Pembeli" icon="📝">
              <div>{d.note}</div>
            </SectionCard>
          )}

          {d.message_to_seller && (
            <SectionCard title="Pesan ke Penjual" icon="💬">
              <div>{d.message_to_seller}</div>
            </SectionCard>
          )}
        </div>

        {/* RIGHT COLUMN */}
        <div className="detail-right">
          <SectionCard title="Ringkasan Pesanan" icon="📋">
            <Row label="No. Pesanan" value={data.order_sn} mono />
            <Row label="Status" value={d.order_status || '—'} />
            <Row label="Dibuat" value={fmtTime(d.create_time)} />
            <Row label="Dibayar" value={d.pay_time ? fmtTime(d.pay_time) : '—'} />
            <Row label="Batas Kirim" value={`${fmtDate(d.ship_by_date)} (${d.days_to_ship ?? '?'} hari)`} />
            <Row label="Pembayaran" value={d.payment_method || '—'} />
            <Row label="Pembeli" value={d.buyer_username || '—'} />
          </SectionCard>

          <SectionCard title="Rincian Pembayaran" icon="💰">
            <Row label="Subtotal Produk" value={fmtAmount(items.reduce((s, it) => s + (it.model_discounted_price || 0) * (it.model_quantity_purchased || 1), 0), d.currency)} />
            <Row label="Ongkir" value={fmtAmount(d.actual_shipping_fee, d.currency)} />
            {d.buyer_paid_amount && <Row label="Total Dibayar" value={fmtAmount(d.buyer_paid_amount, d.currency)} bold />}
            <div className="total-row">
              <span>Total</span>
              <span className="total-amount">{fmtAmount(d.total_amount, d.currency)}</span>
            </div>
          </SectionCard>

          <div className="action-card">
            <button
              onClick={fetchLabel}
              disabled={labelState.status === 'loading' || packages.length === 0}
              className="btn-primary btn-block"
            >
              {labelState.status === 'loading' ? '⏳ Memproses...' : '📦 Cetak Resi / Air Waybill'}
            </button>
            <button onClick={() => window.print()} className="btn-secondary btn-block">
              🖨 Print Detail Pesanan
            </button>
            <button onClick={() => alert('Fitur sync ke Shopee (placeholder)')} className="btn-secondary btn-block">
              🔄 Sync Status
            </button>
            <div className="hint-text">
              💡 Tombol "Cetak Resi" akan generate PDF dari Shopee. Pada sandbox, endpoint shipping document sedang tidak tersedia.
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function SectionCard({ title, icon, children }) {
  return (
    <div className="section-card">
      <div className="section-card-header">
        <span className="section-icon">{icon}</span>
        <h3>{title}</h3>
      </div>
      <div className="section-card-body">{children}</div>
    </div>
  );
}

function Row({ label, value, mono, bold }) {
  return (
    <div className={`detail-row ${bold ? 'bold' : ''}`}>
      <span className="detail-label">{label}</span>
      <span className={`detail-value ${mono ? 'mono' : ''}`}>{value}</span>
    </div>
  );
}