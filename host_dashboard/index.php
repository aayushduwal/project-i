<?php
session_start();
require_once '../db/config.php';

// Check if user is logged in and is a host
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'host') {
    header("Location: /stayhaven/login.php");
    exit();
}

$host_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM listings WHERE host_id = ?) AS total_listings,
        (SELECT COUNT(*) FROM bookings b JOIN listings l ON b.listing_id = l.id WHERE l.host_id = ?) AS total_bookings,
        (SELECT COALESCE(SUM(total_price), 0) FROM bookings b JOIN listings l ON b.listing_id = l.id WHERE l.host_id = ?) AS total_revenue
");
$stmt->bind_param("iii", $host_id, $host_id, $host_id);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();
$stmt->close();

// Fetch all listings with their booking counts and total revenue
$stmt = $conn->prepare("
   SELECT 
       l.*,
       COUNT(DISTINCT b.id) as booking_count,
       COUNT(CASE WHEN b.status = 'confirmed' THEN 1 END) as total_sales,
       MAX(b.created_at) as last_booking_date
   FROM listings l
   LEFT JOIN bookings b ON l.id = b.listing_id
   WHERE l.host_id = ?
   GROUP BY l.id
   ORDER BY l.created_at DESC
");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get monthly revenue data for the chart
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%Y-%m') as month,
        COUNT(*) as booking_count,
        SUM(b.total_price) as revenue
    FROM bookings b
    JOIN listings l ON b.listing_id = l.id
    WHERE l.host_id = ?
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY month ASC
    LIMIT 12
");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$monthly_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Host Dashboard - StayHaven</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <style>
  body {
    font-family: 'Inter', sans-serif;
  }

  .stat-card {
    transition: transform 0.2s ease-in-out;
  }

  .stat-card:hover {
    transform: translateY(-2px);
  }

  .sidebar-link {
    transition: all 0.2s ease-in-out;
  }

  .sidebar-link:hover {
    background-color: rgba(239, 68, 68, 0.1);
  }

  .sidebar-link.active {
    background-color: rgba(239, 68, 68, 0.1);
  }
  </style>
</head>

<body class="bg-gray-50">
  <div class="flex h-screen">
    <aside class="w-64 bg-white border-r border-gray-200 px-4 py-6">
      <div class="flex items-center mb-8">
        <a href="/stayhaven/index.php">

          <h1 class="text-2xl font-bold text-red-600">StayHaven</h1>
        </a>
      </div>
      <nav>
        <ul class="space-y-2">
          <li>
            <a href="#" class="sidebar-link flex active items-center px-4 py-3 text-gray-700 rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" style="margin-right: 10px;" width="18" height="20"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" class="lucide lucide-layout-dashboard">
                <rect width="7" height="9" x="3" y="3" rx="1" />
                <rect width="7" height="5" x="14" y="3" rx="1" />
                <rect width="7" height="9" x="14" y="12" rx="1" />
                <rect width="7" height="5" x="3" y="16" rx="1" />
              </svg>
              Dashboard
            </a>
          </li>
          <li>
            <a href="add_listing.php" class="sidebar-link flex items-center px-4 py-3 text-gray-700 rounded-lg">
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Add New Listing
            </a>
          </li>
          <li>
            <a href="#" class="sidebar-link flex items-center px-4 py-3 text-gray-700 rounded-lg">
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              Bookings
            </a>
          </li>
          <li>
            <a href="#" class="sidebar-link flex items-center px-4 py-3 text-gray-700 rounded-lg">
              <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              Logout
            </a>
          </li>
        </ul>
      </nav>
    </aside>

    <main class="flex-1 overflow-y-auto">
      <div class="bg-white border-b border-gray-200 px-8 py-4">
        <div class="flex justify-end items-center">
          <div class="flex items-center">
            <span class="text-gray-700 mr-4"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
            <img
              src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['user_name']); ?>&background=ef4444&color=fff"
              alt="Profile" class="w-8 h-8 rounded-full">
          </div>
        </div>
      </div>

      <div class="px-8 py-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div class="stat-card bg-white p-6 rounded-lg shadow-sm border border-slate-200">
            <div class="flex justify-between items-start">
              <div>
                <p class="text-gray-500 text-sm">Total Listings</p>
                <h3 class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($stats['total_listings']); ?>
                </h3>
              </div>
              <div class="p-3 bg-red-100 rounded-lg">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
              </div>
            </div>
          </div>
          <div class="stat-card bg-white p-6 rounded-lg shadow-sm border border-slate-200">
            <div class="flex justify-between items-start">
              <div>
                <p class="text-gray-500 text-sm">Total Bookings</p>
                <h3 class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($stats['total_bookings']); ?>
                </h3>
              </div>
              <div class="p-3 bg-red-100 rounded-lg">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
            </div>
          </div>
          <div class="stat-card bg-white p-6 rounded-lg shadow-sm border border-slate-200">
            <div class="flex justify-between items-start">
              <div>
                <p class="text-gray-500 text-sm">Total Revenue</p>
                <h3 class="text-2xl font-bold text-gray-900 mt-1">
                  $<?php echo number_format($stats['total_revenue'], 2); ?></h3>
              </div>
              <div class="p-3 bg-red-100 rounded-lg">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- Revenue Chart -->
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-100 mb-8">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Revenue Overview</h2>
          <canvas id="revenueChart" height="100"></canvas>
        </div>

        <!-- Listings Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-100">
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">All Listings</h2>
            <a href="add_listing.php">
              <button class="px-4 py-2 rounded-md bg-red-600 hover:bg-red-500 outline-none text-white">Add new
                listing</button>
            </a>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bookings
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sales</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($listings as $listing): ?>
                <tr>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($listing['title']); ?>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($listing['location']); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">$<?php echo number_format($listing['price'], 2); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900"><?php echo number_format($listing['booking_count']); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900"><?php echo number_format($listing['total_sales']); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span
                      class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                      Active
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <a href="/stayHaven/host_dashboard/add_listing.php?edit=true&id=<?php echo $listing['id'] ?>"
                      class="text-red-600 hover:text-red-900 mr-3">Edit</a>
                    <a href="#" class="text-red-600 hover:text-red-900">View</a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
  // Initialize Revenue Chart
  const ctx = document.getElementById('revenueChart').getContext('2d');
  const monthlyData = <?php echo json_encode($monthly_data); ?>;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: monthlyData.map(data => {
        const date = new Date(data.month + '-01');
        return date.toLocaleDateString('default', {
          month: 'short'
        });
      }),
      datasets: [{
        label: 'Revenue',
        data: monthlyData.map(data => data.revenue),
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        tension: 0.4,
        fill: true
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            display: true,
            color: 'rgba(0, 0, 0, 0.05)'
          }
        },
        x: {
          grid: {
            display: false
          }
        }
      }
    }
  });
  </script>
</body>

</html>