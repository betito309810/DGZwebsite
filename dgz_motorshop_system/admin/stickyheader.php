<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sticky Header Table Demo</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 20px;
        }

        .window-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            width: 90%;
            max-width: 800px;
            margin: 0 auto;
            overflow: hidden;
        }

        .window-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 24px;
            font-size: 18px;
            font-weight: 600;
        }

        .table-container {
            height: 400px;
            overflow-y: auto;
            position: relative;
            border: 1px solid #e1e5e9;
        }

        .demo-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .demo-table thead th {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            color: #374151;
            font-weight: 600;
            padding: 16px 20px;
            text-align: left;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #d1d5db;
            
            /* Sticky header magic */
            position: sticky;
            position: -webkit-sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .demo-table tbody td {
            padding: 14px 20px;
            border-bottom: 1px solid #f3f4f6;
            color: #4b5563;
            font-size: 14px;
        }

        .demo-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .demo-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Status badges */
        .status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        /* Price styling */
        .price {
            font-weight: 600;
            color: #059669;
        }

        /* Scrollbar styling */
        .table-container::-webkit-scrollbar {
            width: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Demo info */
        .demo-info {
            padding: 20px;
            background: #f8fafc;
            border-top: 1px solid #e1e5e9;
            font-size: 14px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="window-container">
        <div class="window-header">
            📋 Product Inventory - Sticky Header Demo
        </div>
        
        <div class="table-container">
            <table class="demo-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Shell AX7 Scooter Oil</td>
                        <td>SKU001</td>
                        <td class="price">₱550.00</td>
                        <td>7</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Motul Scooter Oil</td>
                        <td>SKU002</td>
                        <td class="price">₱650.00</td>
                        <td>9</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>JVT V3 Pipe for Nmax/Aerox</td>
                        <td>SKU003</td>
                        <td class="price">₱2500.00</td>
                        <td>2</td>
                        <td><span class="status pending">Low Stock</span></td>
                    </tr>
                    <tr>
                        <td>VS1 Tire Black 250ml</td>
                        <td>SKU004</td>
                        <td class="price">₱1000.00</td>
                        <td>12</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Helmet Premium</td>
                        <td>SKU005</td>
                        <td class="price">₱2500.00</td>
                        <td>24</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Interior Cleaner</td>
                        <td>SKU006</td>
                        <td class="price">₱150.00</td>
                        <td>97</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Performance Tires</td>
                        <td>SKU007</td>
                        <td class="price">₱1000.00</td>
                        <td>46</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Engine Oil Filter</td>
                        <td>SKU008</td>
                        <td class="price">₱350.00</td>
                        <td>15</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Brake Fluid DOT 4</td>
                        <td>SKU009</td>
                        <td class="price">₱280.00</td>
                        <td>8</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Chain Lubricant</td>
                        <td>SKU010</td>
                        <td class="price">₱180.00</td>
                        <td>33</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Spark Plug NGK</td>
                        <td>SKU011</td>
                        <td class="price">₱420.00</td>
                        <td>0</td>
                        <td><span class="status inactive">Out of Stock</span></td>
                    </tr>
                    <tr>
                        <td>Air Filter Replacement</td>
                        <td>SKU012</td>
                        <td class="price">₱250.00</td>
                        <td>18</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Coolant Radiator 1L</td>
                        <td>SKU013</td>
                        <td class="price">₱320.00</td>
                        <td>25</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Battery 12V Yuasa</td>
                        <td>SKU014</td>
                        <td class="price">₱1800.00</td>
                        <td>6</td>
                        <td><span class="status pending">Low Stock</span></td>
                    </tr>
                    <tr>
                        <td>LED Headlight Bulb</td>
                        <td>SKU015</td>
                        <td class="price">₱750.00</td>
                        <td>14</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Mirror Set Universal</td>
                        <td>SKU016</td>
                        <td class="price">₱480.00</td>
                        <td>28</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Handlebar Grips</td>
                        <td>SKU017</td>
                        <td class="price">₱150.00</td>
                        <td>42</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Exhaust Silencer</td>
                        <td>SKU018</td>
                        <td class="price">₱3200.00</td>
                        <td>3</td>
                        <td><span class="status pending">Low Stock</span></td>
                    </tr>
                    <tr>
                        <td>Clutch Cable Wire</td>
                        <td>SKU019</td>
                        <td class="price">₱220.00</td>
                        <td>19</td>
                        <td><span class="status active">Active</span></td>
                    </tr>
                    <tr>
                        <td>Speedometer Digital</td>
                        <td>SKU020</td>
                        <td class="price">₱1450.00</td>
                        <td>5</td>
                        <td><span class="status pending">Low Stock</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="demo-info">
            🎯 Scroll down inside the table area to see the sticky header in action!
        </div>
    </div>
</body>
</html>