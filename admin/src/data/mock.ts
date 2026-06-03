export const overviewStats = [
  { label: 'Total Users', value: '1,500', trend: '+26% 24hrs', icon: 'users' as const },
  { label: 'Active riders', value: '150/300', trend: '+26% 24hrs', icon: 'bike' as const },
  { label: 'Pending verification', value: '50', trend: '+26% 24hrs', icon: 'clock' as const },
  { label: 'Revenue', value: '$13,000,000', trend: '+21,741.56% This month', icon: 'shield' as const },
  { label: 'Total orders', value: '304,764', trend: '+21,741.56% This month', icon: 'package' as const },
  { label: 'success rate', value: '99.9%', trend: '+21,741.56% This month', icon: 'trophy' as const },
]

export const recentActivities = [
  {
    id: 1,
    title: 'Delivery completed - User: Sarah J',
    meta: 'Order Id: 87865635221 • Rider: Sheriff',
    time: '10:45:12 PM',
    icon: 'check' as const,
  },
  {
    id: 2,
    title: 'New delivery request - User: Kika',
    meta: 'Order Id: 87865635221 • Rider: Nil',
    time: '10:44:04 PM',
    icon: 'package' as const,
  },
  {
    id: 3,
    title: 'Delivery picked up - User: Mana',
    meta: 'Order Id: 87865635221 • Rider: Udo jinud',
    time: '10:44:00 PM',
    icon: 'truck' as const,
  },
  {
    id: 4,
    title: 'New user signup - User: Chisara',
    meta: 'Order Id: Nil • Rider: Nil',
    time: '10:43:45 PM',
    icon: 'user' as const,
  },
  {
    id: 5,
    title: 'Driver verification approved - User: Danladi',
    meta: 'Order Id: Nil • Rider: Nil',
    time: '10:43:44 PM',
    icon: 'bike' as const,
  },
  {
    id: 6,
    title: 'Delivery request accepted - User: Abohg',
    meta: 'Order Id: 87865635221 • Rider: Sheriff',
    time: '10:43:23 PM',
    icon: 'file' as const,
  },
]

export const customers = [
  { id: 1, name: 'Ozieh Victor', number: '08075710646', status: 'Active' as const, orders: 232, rating: 4.6 },
  { id: 2, name: 'Sarah Johnson', number: '08031234567', status: 'Active' as const, orders: 189, rating: 4.8 },
  { id: 3, name: 'Chisara Okonkwo', number: '08123456789', status: 'Inactive' as const, orders: 45, rating: 4.2 },
  { id: 4, name: 'Kika Adebayo', number: '07098765432', status: 'Active' as const, orders: 312, rating: 4.9 },
  { id: 5, name: 'Mana Ibrahim', number: '09087654321', status: 'Active' as const, orders: 156, rating: 4.5 },
]

export const riders = [
  {
    id: 1,
    name: 'Ozieh Victor',
    number: '08075710646',
    verification: 'Verified' as const,
    vehicle: 'Motorcycle',
    vehicleDetail: 'Baiai2230 Red',
    location: 'Lagos Island',
    trips: 232,
    rating: 4.6,
  },
  {
    id: 2,
    name: 'Sheriff Danladi',
    number: '08031234567',
    verification: 'Verified' as const,
    vehicle: 'Motorcycle',
    vehicleDetail: 'Honda CB125 Black',
    location: 'Ikeja',
    trips: 189,
    rating: 4.8,
  },
  {
    id: 3,
    name: 'Udo Jinud',
    number: '08123456789',
    verification: 'Pending' as const,
    vehicle: 'Bicycle',
    vehicleDetail: 'Giant Escape Blue',
    location: 'Victoria Island',
    trips: 12,
    rating: 4.2,
  },
  {
    id: 4,
    name: 'Nil Rider',
    number: '07098765432',
    verification: 'Rejected' as const,
    vehicle: 'Motorcycle',
    vehicleDetail: 'Yamaha FZ Red',
    location: 'Surulere',
    trips: 0,
    rating: 0,
  },
]

export const orders = [
  {
    id: '#ORD-1234',
    customer: 'Ozieh Victor',
    status: 'Pending' as const,
    pickup: '10, Aremu Ola street',
    dropoff: '25, Admiralty Way',
    amount: '₦24,000',
    createdAt: '10:45am',
  },
  {
    id: '#ORD-1235',
    customer: 'Sarah Johnson',
    status: 'In transit' as const,
    pickup: '5, Allen Avenue',
    dropoff: '12, Ozumba Mbadiwe',
    amount: '₦18,500',
    createdAt: '9:30am',
  },
  {
    id: '#ORD-1236',
    customer: 'Kika Adebayo',
    status: 'Completed' as const,
    pickup: '3, Bourdillon Road',
    dropoff: '8, Ligali Ayorinde',
    amount: '₦32,000',
    createdAt: '8:15am',
  },
  {
    id: '#ORD-1237',
    customer: 'Mana Ibrahim',
    status: 'Cancelled' as const,
    pickup: '15, Herbert Macaulay',
    dropoff: '20, Broad Street',
    amount: '₦15,000',
    createdAt: '7:00am',
  },
]

export const transactions = [
  { id: 'e0f515', amount: '₦ 100.00', time: '3 mins ago', type: 'In' as const, tx: 'https://www.centiv.com/pa...' },
  { id: 'a3b821', amount: '₦ 2,400.00', time: '12 mins ago', type: 'Out' as const, tx: 'https://www.centiv.com/pa...' },
  { id: 'f7c902', amount: '₦ 500.00', time: '1 hr ago', type: 'In' as const, tx: 'https://www.centiv.com/pa...' },
  { id: 'd2e114', amount: '₦ 18,500.00', time: '2 hrs ago', type: 'Out' as const, tx: 'https://www.centiv.com/pa...' },
]
