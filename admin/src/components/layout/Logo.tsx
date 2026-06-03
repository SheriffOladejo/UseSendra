import logoUrl from '../../assets/sendra-logistics-logo.png'

export function Logo({
  className = '',
  size = 'default',
}: {
  className?: string
  size?: 'default' | 'large'
}) {
  const imgClass =
    size === 'large'
      ? 'h-12 w-auto max-w-[220px] object-contain'
      : 'h-10 w-auto max-w-[180px] object-contain object-left'

  return (
    <div className={className}>
      <img
        src={logoUrl}
        alt="Sendra Logistics"
        className={imgClass}
        width={704}
        height={199}
      />
    </div>
  )
}
