import { useState, useEffect, useMemo } from 'react';

const STATUS_TABS = [
  { key: '',                label: 'Siap Diproses',                  match: ['READY_TO_SHIP', 'PROCESSED'] },
  { key: '',                label: 'Pending',                        match: ['UNPAID'] },
  { key: '',                label: 'Proses Gagal',                   match: [] },
  { key: '',                label: 'Menunggu Verifikasi Marketplace', match: [] },
];

export default function Orders({ shopId, onSelectOrder }) {
  const [data, setData] = useState(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [activeTab, setActiveTab] = useState(0);
  const [bulkMenu, setBulkMenu] = useState(null); // null | 'cetak' | 'aksi' | 'impor' | 'ekspor'
  const [bulkStatus, setBulkStatus] = useState(null); // for action feedback

  useEffect(() => {
    fetch(`/api/orders?shop_id=${shopId}&page=${page}&page_size=20`)
      .then(r => r.json())
      .then(d => { if (d.error) throw new Error(d.message || d.error); setData(d); setSelected(new Set()); })
      .catch(e => setError(e.message));
  }, [shopId, page]);

  // Close bulk menu on outside click
  useEffect(() => {
    if (!bulkMenu) return;
    const close = () => setBulkMenu(null);
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [bulkMenu]);

  const counts = data?.counts || {};
  const tabCounts = useMemo(() => ({
    0: (counts['READY_TO_SHIP'] || 0) + (counts['PROCESSED'] || 0),
    1: counts['UNPAID'] || 0,
    2: 0,
    3: 0,
  }), [counts]);

  if (error) return <div className="panel"><div className="error-banner">{error}</div></div>;
  if (!data) return <div className="panel empty">Loading...</div>;

  const items = data.items;
  const allSelected = items.length > 0 && items.every(o => selected.has(o.order_sn));
  const someSelected = selected.size > 0;

  function toggleAll() {
    if (allSelected) setSelected(new Set());
    else setSelected(new Set(items.map(o => o.order_sn)));
  }
  function toggleOne(orderSn) {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(orderSn)) next.delete(orderSn);
      else next.add(orderSn);
      return next;
    });
  }

  async function doBulkAction(action) {
    setBulkStatus(`Memproses ${selected.size} pesanan: ${action}...`);
    setBulkMenu(null);
    // Simulate processing
    await new Promise(r => setTimeout(r, 800));
    setBulkStatus(`✅ Aksi "${action}" selesai untuk ${selected.size} pesanan (simulasi)`);
    setTimeout(() => setBulkStatus(null), 3000);
  }

  return (
    <div className="panel">
      {/* === Toolbar: top action row === */}
      <div className="orders-toolbar">
        <div className="toolbar-left">
          <button
            className={`action-btn mode-primary ${someSelected ? '' : 'disabled'}`}
            disabled={!someSelected}
            onClick={() => doBulkAction('Proses')}
          >
            Proses
          </button>
          <DropdownButton
            label="Cetak Massal"
            disabled={!someSelected}
            open={bulkMenu === 'cetak'}
            onOpen={(e) => { e.stopPropagation(); setBulkMenu('cetak'); }}
            items={[
              { label: 'Cetak Resi / Air Waybill', action: () => doBulkAction('Cetak Resi') },
              { label: 'Cetak Label Pengiriman',  action: () => doBulkAction('Cetak Label') },
              { label: 'Cetak Invoice',           action: () => doBulkAction('Cetak Invoice') },
            ]}
          />
          <DropdownButton
            label="Aksi Massal"
            disabled={!someSelected}
            open={bulkMenu === 'aksi'}
            onOpen={(e) => { e.stopPropagation(); setBulkMenu('aksi'); }}
            items={[
              { label: 'Setujui Pesanan',          action: () => doBulkAction('Setujui') },
              { label: 'Batalkan Pesanan',         action: () => doBulkAction('Batalkan') },
              { label: 'Ubah Status ke Selesai',   action: () => doBulkAction('Selesaikan') },
              { divider: true },
              { label: 'Export CSV',               action: () => doBulkAction('Export CSV') },
            ]}
          />
        </div>
        <div className="toolbar-right">
          <button className="action-btn mode-main" onClick={async () => {
            await fetch(`/api/sync?shop_id=${shopId}&type=orders`, { method: 'POST' });
            window.location.reload();
          }}>
            Sinkronisasi Pesanan
          </button>
          <DropdownButton
            label="Impor"
            open={bulkMenu === 'impor'}
            onOpen={(e) => { e.stopPropagation(); setBulkMenu('impor'); }}
            items={[
              { label: 'Impor dari CSV',  action: () => doBulkAction('Impor CSV') },
              { label: 'Impor dari Tokopedia', action: () => doBulkAction('Impor Tokopedia') },
            ]}
          />
          <DropdownButton
            label="Ekspor"
            open={bulkMenu === 'ekspor'}
            onOpen={(e) => { e.stopPropagation(); setBulkMenu('ekspor'); }}
            items={[
              { label: 'Export CSV',         action: () => doBulkAction('Export CSV') },
              { label: 'Export Excel (.xlsx)', action: () => doBulkAction('Export Excel') },
            ]}
          />
        </div>
      </div>

      {/* === Tabs === */}
      <div className="orders-tabs">
        {STATUS_TABS.map((tab, i) => (
          <button
            key={i}
            className={`tab ${activeTab === i ? 'active' : ''}`}
            onClick={() => setActiveTab(i)}
          >
            {tab.label}
            <span className="tab-num">{tabCounts[i]}</span>
          </button>
        ))}
      </div>

      {/* === Sort row === */}
      <div className="orders-sort">
        <span className="sort-label">Sort:</span>
        <span className="sort-value">Waktu Pembayaran</span>
        <span className="sort-icon">⇅</span>
        <span className="sort-new-badge">New</span>
        <span className="sort-settings" title="Column settings">⚙</span>
      </div>

      {bulkStatus && (
        <div className="bulk-status">{bulkStatus}</div>
      )}

      {/* === Table === */}
      {items.length === 0 ? (
        <div className="empty">No orders — click "Sync now"</div>
      ) : (
        <table className="orders-table clickable-rows">
          <thead>
            <tr>
              <th className="col-check">
                <input
                  type="checkbox"
                  checked={allSelected}
                  ref={el => el && (el.indeterminate = !allSelected && someSelected)}
                  onChange={toggleAll}
                />
              </th>
              <th>
                <div className="th-line th-line-1">Rincian Produk</div>
                <div className="th-line th-line-2">&amp; Pembayaran</div>
              </th>
              <th>
                <div className="th-line th-line-1">Total Pesanan</div>
              </th>
              <th>
                <div className="th-line th-line-1">Penerima</div>
                <div className="th-line th-line-2">&amp; Daerah</div>
              </th>
              <th>
                <div className="th-line th-line-1">Nomor Pesanan</div>
                <div className="th-line th-line-2">&amp; Pembeli</div>
              </th>
              <th>
                <div className="th-line th-line-1">Waktu</div>
              </th>
              <th>
                <div className="th-line th-line-1">Pengaturan Pengiriman</div>
                <div className="th-line th-line-2">&amp; Nomor Resi</div>
              </th>
              <th>
                <div className="th-line th-line-1">Status Marketplace</div>
                <div className="th-line th-line-2">&amp; Status</div>
              </th>
              <th>
                <div className="th-line th-line-1">Aksi</div>
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map(o => (
              <OrderRow
                key={o.order_sn}
                order={o}
                selected={selected.has(o.order_sn)}
                onToggle={() => toggleOne(o.order_sn)}
                onClick={() => onSelectOrder && onSelectOrder(o.order_sn)}
              />
            ))}
          </tbody>
        </table>
      )}

      <Pagination data={data} page={page} setPage={setPage} />
    </div>
  );
}

function DropdownButton({ label, items, disabled, open, onOpen }) {
  return (
    <div className="dropdown-wrap">
      <button
        className={`action-btn mode-main ${disabled ? 'disabled' : ''}`}
        disabled={disabled}
        onClick={onOpen}
      >
        {label}
        <span className="caret">▾</span>
      </button>
      {open && !disabled && (
        <div className="dropdown-menu" onClick={e => e.stopPropagation()}>
          {items.map((it, i) =>
            it.divider ? <div key={i} className="dropdown-divider" /> :
            <button key={i} className="dropdown-item" onClick={it.action}>{it.label}</button>
          )}
        </div>
      )}
    </div>
  );
}

function OrderRow({ order, selected, onToggle, onClick }) {
  return (
    <tr className={selected ? 'row-selected' : ''} onClick={onClick}>
      <td className="col-check" onClick={e => e.stopPropagation()}>
        <input
          type="checkbox"
          checked={selected}
          onChange={onToggle}
        />
      </td>

      {/* Rincian Produk & Pembayaran — with thumbnail */}
      <td>
        <div className="cell-product">
          <div className="cell-product-thumb">
            {order.product_image
              ? <img src={order.product_image} alt="" onError={(e) => e.target.style.display = 'none'} />
              : <div className="thumb-placeholder">📦</div>}
          </div>
          <div className="cell-product-info">
            <div className="cell-product-name">{order.product_name || <span className="muted">—</span>}</div>
            {order.product_model && <div className="muted small">{order.product_model}</div>}
            {order.item_count > 1 && <div className="muted small">+{order.item_count - 1} produk lain</div>}
            <div className="muted small">{order.payment_method || '—'}</div>
          </div>
        </div>
      </td>

      {/* Total Pesanan */}
      <td>
        <div className="cell-total">
          <span className="cell-amount">{order.total_amount != null ? order.total_amount.toLocaleString('id-ID') : '—'}</span>
          <span className="cell-currency">{order.currency || ''}</span>
        </div>
      </td>

      {/* Penerima & Daerah */}
      <td>
        <div className="cell-recipient">
          <div>{order.recipient_name || <span className="muted">—</span>}</div>
          <div className="muted small">{order.recipient_city || '—'}{order.recipient_state ? `, ${order.recipient_state}` : ''}</div>
        </div>
      </td>

      {/* Nomor Pesanan & Pembeli */}
      <td>
        <div className="cell-order-id">
          <div className="mono small">{order.order_sn}</div>
          <div className="muted small">{order.buyer_username || '—'}</div>
        </div>
      </td>

      {/* Waktu */}
      <td>
        <div className="cell-time">
          <div>{order.create_time ? new Date(order.create_time * 1000).toLocaleDateString('id-ID', {
            day: '2-digit', month: 'short', year: 'numeric'
          }) : '—'}</div>
          <div className="muted small">{order.create_time ? new Date(order.create_time * 1000).toLocaleTimeString('id-ID', {
            hour: '2-digit', minute: '2-digit'
          }) : ''}</div>
        </div>
      </td>

      {/* Pengaturan Pengiriman & Nomor Resi */}
      <td>
        <div className="cell-shipping">
          <div className="small">{order.shipping_carrier || <span className="muted">—</span>}</div>
          {order.package_number && <div className="muted mono small">{order.package_number}</div>}
          {!order.package_number && <div className="muted small">Belum diatur</div>}
        </div>
      </td>

      {/* Status Marketplace & Status */}
      <td>
        <div className="cell-status">
          <span className={`status-badge ${order.status || ''}`}>{order.status || '—'}</span>
          {order.logistics_status && (
            <div className="muted small" style={{ marginTop: 2 }}>{order.logistics_status}</div>
          )}
        </div>
      </td>

      {/* Aksi */}
      <td onClick={e => e.stopPropagation()}>
        <div className="cell-action">
          <button className="btn-tiny btn-primary-tiny" onClick={onClick}>Detail</button>
        </div>
      </td>
    </tr>
  );
}

function Pagination({ data, page, setPage }) {
  if (!data || data.total_pages <= 1) return null;
  return (
    <div className="pagination">
      <span>Page {page} of {data.total_pages}</span>
      <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Prev</button>
      <button disabled={page >= data.total_pages} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>
  );
}