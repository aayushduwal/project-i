<!-- booking-form.php -->
<?php
session_start();
require_once __DIR__ . '/db/config.php';
require_once 'EsewaPayment.php';

function getUserDetails($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, name, email, role, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

$isLoggedIn = isset($_SESSION['user_id']);
$userDetails = null;

if ($isLoggedIn) {
    $userDetails = getUserDetails($conn, $_SESSION['user_id']);
    $_SESSION['email'] = $userDetails['email'];
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$room_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM listings WHERE id = ?");
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    header("Location: index.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT b.check_in, b.check_out, COUNT(*) as booked_count 
    FROM bookings b 
    WHERE b.listing_id = ? 
    AND b.check_out >= CURRENT_DATE()
    GROUP BY b.check_in, b.check_out
");
$stmt->bind_param("i", $room_id);
$stmt->execute();
$result = $stmt->get_result();

$booked_dates = [];
while ($booking = $result->fetch_assoc()) {
    $period = new DatePeriod(
        new DateTime($booking['check_in']),
        new DateInterval('P1D'),
        (new DateTime($booking['check_out']))->modify('+1 day')
    );
    
    foreach ($period as $date) {
        $date_str = $date->format('Y-m-d');
        if (!isset($booked_dates[$date_str])) {
            $booked_dates[$date_str] = 0;
        }
        $booked_dates[$date_str] += $booking['booked_count'];
    }
}
$stmt->close();

$booked_dates_json = json_encode($booked_dates);

$room_quantity = $room['quantity'];

function validateBookingDates($conn, $listing_id, $check_in, $check_out, $requested_quantity) {
    // First get the total rooms available for this listing
    $stmt = $conn->prepare("SELECT quantity FROM listings WHERE id = ?");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $listing = $result->fetch_assoc();
    $total_rooms = $listing['quantity'];
    $stmt->close();

    // Then check existing bookings for the date range
    $stmt = $conn->prepare("
        SELECT SUM(room_quantity) as booked_rooms
        FROM bookings
        WHERE listing_id = ?
        AND status != 'cancelled'
        AND (
            (check_in <= ? AND check_out >= ?) OR
            (check_in <= ? AND check_out >= ?) OR
            (? BETWEEN check_in AND check_out) OR
            (? BETWEEN check_in AND check_out)
        )
    ");
    
    $stmt->bind_param("issssss", 
        $listing_id,
        $check_out, $check_in,
        $check_in, $check_out,
        $check_in, $check_out
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_assoc();
    $booked_rooms = $bookings['booked_rooms'] ?? 0;
    $stmt->close();
    
    // Check if there are enough rooms available
    $available_rooms = $total_rooms - $booked_rooms;
    return $available_rooms >= $requested_quantity;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book <?php echo htmlspecialchars($room['title']); ?></title>
  <link rel="stylesheet" href="css/index.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="//unpkg.com/alpinejs" defer></script>
</head>

<body class="bg-gray-50">
  <header class="bg-white border-b border-slate-200">
    <nav class="container mx-auto px-4">
      <div class="flex items-center justify-between h-16 w-full">
        <div class="flex items-center gap-8">
          <a href="/stayHaven/index.php" class="text-xl font-bold text-rose-600">
            StayHaven
          </a>

          <div class="hidden md:block">
            <ul class="flex items-center gap-6">
              <li>
                <a href="/stayhaven/index.php" class="text-slate-600 hover:text-slate-900">Home</a>
              </li>
              <li>
                <a href="/stayhaven/listings.php" class="text-slate-600 hover:text-slate-900">Listings</a>
              </li>
            </ul>
          </div>
        </div>

        <?php if ($isLoggedIn): ?>
        <div class="relative" x-data="{ open: false }">
          <div class="flex items-center gap-2">
            <?php if ($userDetails['role'] === 'host'): ?>
            <a href="/stayhaven/host_dashboard/index.php"
              class="bg-rose-600 visited:text-white hover:bg-rose-700 text-white px-4 py-2 rounded-lg transition-colors">
              Dashboard
            </a>
            <?php endif; ?>

            <button @click="open = !open" @click.outside="open = false"
              class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 px-4 py-2 rounded-lg transition-colors">
              <div class="w-8 h-8 bg-rose-600 rounded-full flex items-center justify-center">
                <span class="text-white font-medium">
                  <?php echo substr($userDetails['name'], 0, 1); ?>
                </span>
              </div>
              <span class="text-slate-700"><?php echo $userDetails['name']; ?></span>
              <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400"></i>
            </button>
          </div>

          <div x-show="open" x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50">
            <div class="px-4 py-2 border-b border-slate-200">
              <p class="text-sm text-slate-500">Signed in as</p>
              <p class="text-sm font-medium truncate"><?php echo $userDetails['email']; ?></p>
            </div>

            <?php if ($userDetails['role'] !== 'host'): ?>
            <a href="/stayhaven/bookings.php"
              class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              <i data-lucide="calendar" class="w-4 h-4"></i>
              My Bookings
            </a>

            <a href="/stayhaven/favorites.php"
              class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              <i data-lucide="heart" class="w-4 h-4"></i>
              Favorites
            </a>
            <?php endif; ?>

            <?php if ($userDetails['role'] === 'host'): ?>
            <a href="/stayhaven/user_profile.php"
              class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
              Dashboard
            </a>
            <a href="/stayhaven/host-profile.php?id=<?php echo $userDetails['id']; ?>"
              class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              <i data-lucide="user" class="w-4 h-4"></i>
              Host Profile
            </a>
            <?php endif; ?>

            <a href="/stayhaven/user_profile.php"
              class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              <i data-lucide="settings" class="w-4 h-4"></i>
              Settings
            </a>

            <div class="border-t border-slate-200 mt-1">
              <a href="logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                Logout
              </a>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="flex items-center gap-4">
          <a href="/stayhaven/login.php" class="text-slate-600 hover:text-slate-900">
            Login
          </a>
          <a href="/stayhaven/signup.php"
            class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg transition-colors">
            Register
          </a>
        </div>
        <?php endif; ?>
      </div>
    </nav>
  </header>

  <div class="max-w-2xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">Book <span
        class="text-rose-600"><?php echo htmlspecialchars($room['title']); ?></span></h1>

    <?php if (empty($userDetails['phone'])): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd"
              d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
              clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <p class="text-sm text-yellow-700">
            Please add your phone number before booking.
            <a href="/stayhaven/user_profile.php" class="font-medium underline text-yellow-700 hover:text-yellow-600">
              Update your profile here
            </a>
          </p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <form id="bookingForm" class="space-y-4">
      <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">

      <div>
        <label class="block mb-2">Check-in Date:</label>
        <input type="date" name="check_in" required min="<?php echo date('Y-m-d'); ?>"
          class="w-full p-2 border rounded">
      </div>

      <div>
        <label class="block mb-2">Check-out Date:</label>
        <input type="date" name="check_out" required min="<?php echo date('Y-m-d'); ?>"
          class="w-full p-2 border rounded">
      </div>

      <div>
        <label class="block mb-2">Number of Guests:</label>
        <input type="number" name="guests" required min="1" default="1" max="<?php echo $room['max_guests']; ?>"
          class="w-full p-2 border rounded" oninput="validateGuests(this)">
      </div>

      <div class="mb-4">
        <label for="room_quantity" class="block text-sm font-medium text-gray-700 mb-1">Number of Rooms</label>
        <select name="room_quantity" id="room_quantity" class="w-full p-2 border border-gray-300 rounded-md" required>
          <?php
          $max_rooms = min($room['quantity'], 5); // Limit to 5 rooms per booking
          for ($i = 1; $i <= $max_rooms; $i++) {
              echo "<option value=\"$i\">$i</option>";
          }
          ?>
        </select>
      </div>

      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-semibold mb-2">Price Details</h2>
        <p>Price per night: NPR.<?php echo number_format($room['price'], 2); ?></p>
        <div id="totalPrice" class="hidden mt-2">
          <p>Number of nights: <span id="numberOfNights">0</span></p>
          <p>Total price: NPR.<span id="priceAmount">0</span></p>
        </div>
      </div>

      <div class="space-y-2">
        <label class="block font-medium">Payment Method:</label>
        <div class="space-y-2">
          <label class="flex items-center">
            <input type="radio" name="payment_method" value="esewa" class="mr-2">
            eSewa
          </label>
          <label class="flex items-center">
            <input type="radio" name="payment_method" value="cash" class="mr-2">
            Cash on arrival
          </label>
        </div>
      </div>

      <button type="submit" class="w-full bg-rose-600 text-white py-2 px-4 rounded hover:bg-rose-700">
        Reserve Room
      </button>
    </form>

    <div id="esewaPayment"></div>
  </div>

  <script>
  const bookedDates = <?php echo json_encode($booked_dates); ?>;
  const roomQuantity = <?php echo $room_quantity; ?>;
  const checkInInput = document.querySelector('input[name="check_in"]');
  const checkOutInput = document.querySelector('input[name="check_out"]');
  const pricePerNight = <?php echo $room['price']; ?>;
  const totalPriceDiv = document.getElementById('totalPrice');
  const priceAmount = document.getElementById('priceAmount');
  const bookingForm = document.getElementById('bookingForm');
  const esewaPaymentDiv = document.getElementById('esewaPayment');

  function isDateBooked(date) {
    const dateString = date.toISOString().split('T')[0];
    return bookedDates[dateString] >= roomQuantity;
  }

  function setDateConstraints(input) {
    const currentDate = new Date();
    currentDate.setHours(0, 0, 0, 0);

    input.addEventListener('input', function() {
      const selectedDate = this.value;
      if (bookedDates[selectedDate] >= roomQuantity) {
        alert('No rooms available for this date. Please select another date.');
        this.value = '';
      }
      calculatePrice();
    });
  }

  function calculatePrice() {
    if (checkInInput.value && checkOutInput.value) {
      const start = new Date(checkInInput.value);
      const end = new Date(checkOutInput.value);
      const days = (end - start) / (1000 * 60 * 60 * 24);
      const quantity = parseInt(roomQuantitySelect.value);
      const total = days * pricePerNight * quantity;

      totalPriceDiv.classList.remove('hidden');
      document.getElementById('numberOfNights').textContent = days;
      priceAmount.textContent = total.toFixed(2);
      return total;
    }
    return 0;
  }

  setDateConstraints(checkInInput);
  setDateConstraints(checkOutInput);

  checkInInput.addEventListener('change', function() {
    if (this.value) {
      checkOutInput.min = this.value;
      if (checkOutInput.value && checkOutInput.value < this.value) {
        checkOutInput.value = '';
      }
      calculatePrice();
    }
  });

  function validateGuests(input) {
    const maxGuests = <?php echo $room['max_guests']; ?>;
    if (parseInt(input.value) > maxGuests) {
      alert(`Maximum ${maxGuests} guests allowed for this listing`);
      input.value = maxGuests;
    }
    if (parseInt(input.value) < 1) {
      input.value = 1;
    }
  }

  function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.innerHTML = `
      <div class="fixed top-4 right-4 bg-red-50 text-red-600 px-4 py-3 rounded-lg shadow-lg border border-red-200 flex items-center gap-2 animate-slide-in">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
        </svg>
        ${message}
      </div>
    `;
    document.body.appendChild(errorDiv);

    // Remove after 5 seconds
    setTimeout(() => {
      errorDiv.classList.add('animate-slide-out');
      setTimeout(() => errorDiv.remove(), 300);
    }, 5000);
  }

  // Add this CSS to your styles
  const style = document.createElement('style');
  style.textContent = `
    .animate-slide-in {
      animation: slideIn 0.3s ease-out;
    }
    
    .animate-slide-out {
      animation: slideOut 0.3s ease-out;
    }
    
    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }
    
    @keyframes slideOut {
      from {
        transform: translateX(0);
        opacity: 1;
      }
      to {
        transform: translateX(100%);
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(style);

  // Update your form submission handler
  bookingForm.addEventListener('submit', async function(e) {
    e.preventDefault();

    <?php if (empty($userDetails['phone'])): ?>
    showError('Please add your phone number in your profile before booking.');
    return;
    <?php endif; ?>

    const formData = {
      room_id: <?php echo $room_id; ?>,
      check_in: checkInInput.value,
      check_out: checkOutInput.value,
      guests: parseInt(document.querySelector('input[name="guests"]').value),
      room_quantity: parseInt(document.getElementById('room_quantity').value),
      amount: calculatePrice(),
      payment_method: document.querySelector('input[name="payment_method"]:checked')?.value
    };

    // Validate all required fields
    if (!formData.payment_method) {
      showError('Please select a payment method');
      return;
    }

    if (!formData.check_in || !formData.check_out) {
      showError('Please select both check-in and check-out dates');
      return;
    }

    if (!formData.room_quantity || formData.room_quantity < 1) {
      showError('Please select number of rooms');
      return;
    }

    try {
      const totalAmount = calculatePrice();
      if (!totalAmount || totalAmount <= 0) {
        showError('Invalid booking amount');
        return;
      }

      // Show loading state
      const submitButton = this.querySelector('button[type="submit"]');
      const originalButtonText = submitButton.innerHTML;
      submitButton.disabled = true;
      submitButton.innerHTML = `
            <svg class="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Processing...
        `;

      const response = await fetch('process-booking.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();

      if (data.error) {
        throw new Error(data.message || 'Failed to process booking');
      }

      if (data.payment_required && data.esewaForm) {
        esewaPaymentDiv.innerHTML = data.esewaForm;
        document.getElementById('esewaForm').submit();
      } else if (data.success) {
        window.location.href = 'booking-success.php?id=' + data.booking_id;
      }

    } catch (error) {
      console.error('Error:', error);
      showError(error.message);
    } finally {
      // Restore button state
      submitButton.disabled = false;
      submitButton.innerHTML = originalButtonText;
    }
  });

  // Add validation to date inputs
  checkInInput.addEventListener('change', function() {
    const selectedDate = new Date(this.value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (selectedDate < today) {
      showError('Check-in date cannot be in the past');
      this.value = '';
      return;
    }

    if (checkOutInput.value) {
      const checkOut = new Date(checkOutInput.value);
      if (checkOut <= selectedDate) {
        showError('Check-out date must be after check-in date');
        checkOutInput.value = '';
      }
    }
  });

  checkOutInput.addEventListener('change', function() {
    if (checkInInput.value) {
      const checkIn = new Date(checkInInput.value);
      const selectedDate = new Date(this.value);

      if (selectedDate <= checkIn) {
        showError('Check-out date must be after check-in date');
        this.value = '';
      }
    } else {
      showError('Please select a check-in date first');
      this.value = '';
    }
  });

  const roomQuantitySelect = document.getElementById('room_quantity');
  roomQuantitySelect.addEventListener('change', calculatePrice);
  </script>
</body>

</html>