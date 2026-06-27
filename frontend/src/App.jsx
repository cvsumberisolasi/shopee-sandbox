import { useState, useEffect } from 'react';
import Dashboard from './pages/Dashboard.jsx';
import Products from './pages/Products.jsx';
import Orders from './pages/Orders.jsx';
import OrderDetail from './pages/OrderDetail.jsx';
import { SHOP_ID } from './config.js';

export default function App() {
  const [tab, setTab] = useState('dashboard');
  const [selectedOrderSn, setSelectedOrderSn] = useState(null);
  const [dashboard, setDashboard] = useState(null);
  const [error, setError] = useState(null);
  const [syncing, setSyncing] = useState(false);

  function go(tabName, orderSn = null) {
    setTab(tabName);
    setSelectedOrderSn(orderSn);
  }

  async function loadDashboard() {
    try {
      setError(null);
      const res = await fetch(`/api/dashboard?shop_id=${SHOP_ID}`);
      const data = await res.json();
      if (data.error) throw new Error(data.message || data.error);
      setDashboard(data);
    } catch (e) {
      setError(e.message);
    }
  }

  useEffect(() => { loadDashboard(); }, []);

  async function syncNow(type = 'all') {
    setSyncing(true);
    try {
      const res = await fetch(`/api/sync?shop_id=${SHOP_ID}&type=${type}`, { method: 'POST' });
      const data = await res.json();
      if (data.error) throw new Error(data.message || data.error);
      await loadDashboard();
    } catch (e) {
      setError(e.message);
    } finally {
      setSyncing(false);
    }
  }

  return (
    <div className="app">
      <header>
        <h1>Shopee Sandbox Dashboard</h1>
        <nav>
          <button className={tab === 'dashboard' ? 'active' : ''} onClick={() => go('dashboard')}>Dashboard</button>
          <button className={tab === 'products' ? 'active' : ''} onClick={() => go('products')}>Products</button>
          <button className={tab === 'orders' || tab === 'order-detail' ? 'active' : ''} onClick={() => go('orders')}>Orders</button>
        </nav>
      </header>

      <div style={{ marginBottom: 16, display: 'flex', gap: 12 }}>
        <button className="btn-primary" disabled={syncing} onClick={() => syncNow('all')}>
          {syncing ? 'Syncing...' : 'Sync now'}
        </button>
        <button onClick={async () => {
          const r = await fetch('/api/auth/url');
          const d = await r.json();
          if (d.url) window.location.href = d.url;
        }}>
          Authorize Shopee
        </button>
      </div>

      {error && <div className="error-banner">Error: {error}</div>}

      {tab === 'dashboard' && <Dashboard data={dashboard} />}
      {tab === 'products' && <Products shopId={SHOP_ID} />}
      {tab === 'orders' && <Orders shopId={SHOP_ID} onSelectOrder={(sn) => go('order-detail', sn)} />}
      {tab === 'order-detail' && <OrderDetail orderSn={selectedOrderSn} onBack={() => go('orders')} />}
    </div>
  );
}