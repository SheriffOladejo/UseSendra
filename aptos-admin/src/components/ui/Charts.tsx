export function UsageTrendsChart({
  data,
}: {
  data: Array<{ day: number; activeUsers: number }>
}) {
  const width = 800
  const height = 220
  const padding = { top: 20, right: 20, bottom: 30, left: 40 }
  const chartW = width - padding.left - padding.right
  const chartH = height - padding.top - padding.bottom

  const maxY = Math.max(2, ...data.map((d) => d.activeUsers))
  const points = data.map((d, i) => {
    const x = padding.left + (i / Math.max(data.length - 1, 1)) * chartW
    const y = padding.top + chartH - (d.activeUsers / maxY) * chartH
    return { x, y }
  })

  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ')
  const areaPath = `${linePath} L ${points[points.length - 1]?.x ?? 0} ${padding.top + chartH} L ${points[0]?.x ?? 0} ${padding.top + chartH} Z`

  const yTicks = [0, maxY / 2, maxY]

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label="Usage trends">
      {yTicks.map((tick) => {
        const y = padding.top + chartH - (tick / maxY) * chartH
        return (
          <g key={tick}>
            <line
              x1={padding.left}
              y1={y}
              x2={width - padding.right}
              y2={y}
              stroke="#e8ecea"
              strokeDasharray="4 4"
            />
            <text x={padding.left - 8} y={y + 4} textAnchor="end" className="fill-gray-400 text-[10px]">
              {tick.toFixed(1)}
            </text>
          </g>
        )
      })}
      <path d={areaPath} fill="url(#chartGradient)" opacity={0.35} />
      <path d={linePath} fill="none" stroke="#3ddc97" strokeWidth={2.5} strokeLinecap="round" />
      {points.map((p, i) => (
        <circle key={i} cx={p.x} cy={p.y} r={3} fill="#3ddc97" />
      ))}
      <text x={padding.left} y={height - 6} className="fill-gray-500 text-[11px]">
        Time (days)
      </text>
      <text
        x={12}
        y={padding.top + chartH / 2}
        transform={`rotate(-90 12 ${padding.top + chartH / 2})`}
        className="fill-gray-500 text-[11px]"
      >
        Active users
      </text>
      <defs>
        <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#3ddc97" />
          <stop offset="100%" stopColor="#3ddc97" stopOpacity={0} />
        </linearGradient>
      </defs>
    </svg>
  )
}

export function ActivityBarChart({
  data,
}: {
  data: Array<{ label: string; count: number }>
}) {
  const max = Math.max(1, ...data.map((d) => d.count))

  return (
    <div className="space-y-3 px-2 py-4">
      {data.length === 0 ? (
        <p className="text-sm text-sendra-muted">No activity data yet.</p>
      ) : (
        data.map((item) => (
          <div key={item.label}>
            <div className="mb-1 flex justify-between text-sm">
              <span className="text-gray-700">{item.label}</span>
              <span className="font-medium text-gray-900">{item.count}</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-gray-100">
              <div
                className="h-full rounded-full bg-sendra-accent"
                style={{ width: `${(item.count / max) * 100}%` }}
              />
            </div>
          </div>
        ))
      )}
    </div>
  )
}
