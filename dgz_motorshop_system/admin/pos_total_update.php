/* Reset and base styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    color: #333;
    line-height: 1.6;
}

/* Sidebar */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    background: #2c3e50;
    z-index: 1000;
    transition: transform 0.3s ease;
    overflow-y: auto;
}

/* === NEW: Top bar with search + total === */
.pos-top-bar{
    display:flex;
    justify-content: space-between;
    align-items: center;
    margin:20px 0;
    background:#fff;
    padding:12px 16px;
    border:1px solid #e2e8f0;
    border-radius:10px;
    box-shadow:0 2px 8px rgba(0,0,0,0.05);
}

.pos-top-bar .top-total{
    font-weight:700;
    font-size:18px;
    color:#1f2937;
}

/* Keep existing styles below */

/* POS Table Container - fixed height, scrollable, for sticky header */
.pos-table-container {
    height: 610px;
    overflow-y: auto;
    overflow-x: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    margin-top: 20px;
    position: relative;
    isolation: isolate;
    box-shadow: 0 2px 15px rgba(0,0,0,0.05);
}

#posTable {
    width: 100%;
    background: #fff;
    border-collapse: collapse;
    margin: 0;
    table-layout: fixed;
}

#posTable th {
    position: sticky;
    letter-spacing: 0.5px;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    background-image: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 16px 18px;
    border-bottom: 2px solid #e2e8f0;
    color: #222;
    text-align: left;
    font-weight: 600;
}

#posTable td {
    padding: 16px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #4a5568;
    font-size: 14px;
    background: #fff;
    word-wrap: break-word;
}

/* Totals panel at bottom remains the same */
.totals-panel{
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.05);
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}

.totals-item label{
    display:block;
    font-size:12px;
    color:#6b7280;
    margin-bottom:6px;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.totals-item .value{
    font-weight: 700;
    font-size: 20px;
    color: #1f2937;
}
