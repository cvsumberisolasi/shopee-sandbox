import { useState, useEffect } from 'react';

export default function Products({ shopId }) {
  const [data, setData] = useState(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetch(`/api/products?shop_id=${shopId}&page=${page}&page_size=20`)
      .then(r => r.json())
      .then(d => { if (d.error) throw new Error(d.message || d.error); setData(d); })
      .catch(e => setError(e.message));
  }, [shopId, page]);

  if (error) return <div className="panel"><div className="error-banner">{error}</div></div>;
  if (!data) return <div className="panel empty">Loading...</div>;

  return (
    <div className="panel">
      <h2>Products ({data.total})</h2>
      {data.items.length === 0 ? (
        <div className="empty">No products yet — click "Sync now"</div>
      ) : (
        <table>
          <thead>
            <tr><th>Item ID</th><th>Name</th><th>SKU</th><th>Price</th><th>Stock</th><th>Status</th><th>Synced</th></tr>
          </thead>
          <tbody>
            {data.items.map(p => (
              <tr key={p.item_id}>
                <td>{p.item_id}</td>
                <td>{p.name || '—'}</td>
                <td>{p.sku || '—'}</td>
                <td>{p.price != null ? (p.price / 100000).toFixed(2) : '—'}</td>
                <td>{p.stock ?? '—'}</td>
                <td><span className={`status ${p.status || ''}`}>{p.status || '—'}</span></td>
                <td>{p.synced_at ? new Date(p.synced_at * 1000).toLocaleString() : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <Pagination data={data} page={page} setPage={setPage} />
    </div>
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