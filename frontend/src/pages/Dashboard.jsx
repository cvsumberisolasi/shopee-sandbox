function timeAgo(unixTs) {
  if (!unixTs) return 'never';
  const diff = Math.floor(Date.now() / 1000) - Number(unixTs);
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

export default function Dashboard({ data }) {
  if (!data) return <div className="panel empty">Loading...</div>;
  const shop = data.shop;
  const counts = data.counts || {};
  const log = data.sync_log || [];

  return (
    <>
      <div className="cards">
        <div className="card">
          <div className="label">Shop</div>
          <div className="value">{shop?.name || '—'}</div>
          <div className="sub">ID {shop?.shop_id || '—'} · {shop?.region || '—'}</div>
        </div>
        <div className="card">
          <div className="label">Status</div>
          <div className="value" style={{ fontSize: 18 }}>{shop?.status || '—'}</div>
          <div className="sub">synced {timeAgo(shop?.synced_at)}</div>
        </div>
        <div className="card">
          <div className="label">Products</div>
          <div className="value">{counts.products ?? 0}</div>
        </div>
        <div className="card">
          <div className="label">Orders</div>
          <div className="value">{counts.orders ?? 0}</div>
        </div>
      </div>

      <div className="panel">
        <h2>Recent Sync Log</h2>
        {log.length === 0 ? (
          <div className="empty">No sync yet — run sandbox_auth.php then sync</div>
        ) : (
          <table>
            <thead>
              <tr><th>Time</th><th>Resource</th><th>Status</th><th>Message</th></tr>
            </thead>
            <tbody>
              {log.map(row => (
                <tr key={row.id}>
                  <td>{timeAgo(row.ran_at)}</td>
                  <td>{row.resource}</td>
                  <td><span className={`status ${row.status}`}>{row.status}</span></td>
                  <td style={{ maxWidth: 400, overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.message}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}