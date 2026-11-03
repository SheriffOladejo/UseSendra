import sendraLogo from '../../assets/sendra-logo.png'

export function Logo({
  className = '',
  size = 'default',
}: {
  className?: string
  size?: 'default' | 'large'
}) {
  const imgHeight = size === 'large' ? 'h-10' : 'h-8'

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <img src={sendraLogo} alt="Sendra Logo" className={`${imgHeight} w-auto object-contain`} />
    </div>
  )
}
