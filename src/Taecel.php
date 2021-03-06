<?php namespace Taecel\Taecel;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Taecel\Taecel\Components\Bolsa;
use Taecel\Taecel\Components\Producto;
use Taecel\Taecel\Components\Proveedor;
use Taecel\Taecel\Components\InformacionDeTransaccion;
use Taecel\Taecel\Throwables\ServerIsOffline;
use Throwable;
use Exception;

class Taecel
{

    private string $url;
    private string|null $key;
    private string|null $nip;
    private bool $validate_online;

    private const CACHE_KEY = 'productos_availables';

    const TIEMPO_AIRE = 1;
    const PAQUETES = 2;
    const SERVICIOS = 3;
    const GIF_CARDS = 4;

    /** @var Collection<Proveedor> */
    private Collection $proveedores_tae;
    /** @var Collection<Proveedor> */
    private Collection $proveedores_paquetes;
    /** @var Collection<Proveedor> */
    private Collection $proveedores_servicios;
    /** @var Collection<Proveedor> */
    private Collection $proveedores_gifcards;
    /** @var Collection<Producto> */
    private Collection $productos;

    private array $txnRules = [
        'producto' => 'required',
        'referencia' => 'required',
        'monto'    => 'required'
    ];

    private array $statusTXN = [
        'transaction_id' => 'required'
    ];

    private bool $productosCargados = false;

    public function __construct(string|null $key, string|null $nip)
    {
        $this->key = $key;
        $this->nip = $nip;
        $this->url = config('taecel.url');
        $this->validate_online = config('taecel.validate_online', false);
        $this->proveedores_tae = new Collection();
        $this->proveedores_paquetes = new Collection();
        $this->proveedores_servicios = new Collection();
        $this->proveedores_gifcards = new Collection();
        $this->productos = new Collection();
    }

    private function url($url) : string
    {
        return "{$this->url}/{$url}";
    }

    public static function create() : Taecel
    {
        return new Taecel(config('taecel.key'), config('taecel.nip'));
    }

    /**
     * @return array
     * @throws Throwable
     */
    public function getBalance() : array
    {
        if ($this->validate_online) throw_unless($this->isOnline(), ServerIsOffline::class);
        $httpResponse = Http::asForm()->post($this->url('getBalance'), [
            'key' => $this->key,
            'nip' => $this->nip
        ]);
        throw_if($httpResponse->status() !== 200, new Exception(sprintf('%d error', $httpResponse->status())));
        $data = $httpResponse->json();
        throw_unless($data['success'], new Exception(sprintf(__('message %s, error: %d'), $data['message'], $data['error'])));
        $bolsas = [];
        foreach ($data['data'] as $bolsa_data)
        {
            $bolsas[] = new Bolsa($bolsa_data['ID'], $bolsa_data['Bolsa'], floatval($bolsa_data['Saldo']) );
        }
        return $bolsas;
    }

    public function getProducts() : Collection
    {
        if ($this->validate_online) throw_unless($this->isOnline(), ServerIsOffline::class);
        if(!$this->productosCargados)
        {
            if(!Cache::has(Taecel::CACHE_KEY))
            {
                $httpResponse = Http::asForm()->post($this->url('getProducts'), [
                    'key' => $this->key,
                    'nip' => $this->nip
                ]);
                throw_if($httpResponse->status() !== 200, new Exception(sprintf('%d error', $httpResponse->status())));
                $data = $httpResponse->json();
                throw_unless($data['success'], new Exception(sprintf(__('%s, error: %d'), $data['message'], $data['error'])));
                $_data = $data['data'];
                Cache::add(Taecel::CACHE_KEY, $_data);
            }
            else
            {
                $_data = Cache::get(Taecel::CACHE_KEY);
            }
            foreach ($_data['carriers'] as $carrier)
            {
                $proveedor =  new Proveedor($carrier);
                switch ($proveedor->getCategoriaId())
                {
                    case self::TIEMPO_AIRE:
                        if(!$this->proveedores_tae->contains($proveedor)){
                            $this->proveedores_tae->add($proveedor);
                        }
                        break;
                    case self::SERVICIOS:
                        if(!$this->proveedores_servicios->contains($proveedor)){
                            $this->proveedores_servicios->add($proveedor);
                        }
                        break;
                    case self::PAQUETES:
                        if(!$this->proveedores_servicios->contains($proveedor)){
                            $this->proveedores_paquetes->add($proveedor);
                        }
                        break;
                    case self::GIF_CARDS:
                        if(!$this->proveedores_gifcards->contains($proveedor)){
                            $this->proveedores_gifcards->add($proveedor);
                        }
                        break;
                    default:
                        break;
                }
            }
            foreach ($_data['productos'] as $producto)
            {
                if(!$this->productos->contains($producto)){
                    $this->productos->add(new Producto($producto));
                }
            }
        }
        return $this->productos;
    }

    public function getProveedoresTae() : Collection
    {
        if(!$this->productosCargados) $this->getProducts();
        return $this->proveedores_tae;
    }

    public function getProveedoresServicios() : Collection
    {
        if(!$this->productosCargados) $this->getProducts();
        return $this->proveedores_servicios;
    }

    public function getProveedoresPaquetes() : Collection
    {
        if(!$this->productosCargados) $this->getProducts();
        return $this->proveedores_paquetes;
    }

    public function getProveedoresGifCards() : Collection
    {
        if(!$this->productosCargados) $this->getProducts();
        return $this->proveedores_gifcards;
    }

    private function isOnline() : bool
    {
        return boolval(@fsockopen("www.google.com", 80));
    }

    public function getProductsByCarrier(int $carrier_id) : Collection
    {
        if(!$this->productosCargados) $this->getProducts();
        return $this->productos->where(function(Producto $producto) use ($carrier_id){
            return $producto->getCarrierId() === $carrier_id;
        });
    }

    /**
     * Necesita los siguientes par??metros
     * 'producto' => 'required',
     * 'referencia' => 'required',
     * 'monto'    => 'required'
     * @param array $data
     * @return string
     * @throws Throwable
     */
    public function requestTxn(array $data) : string
    {
        if ($this->validate_online) throw_unless($this->isOnline(), ServerIsOffline::class);
        $validator = Validator::make($data, $this->txnRules);
        throw_if($validator->fails(), ValidationException::class);
        $httpResponse = Http::asForm()->timeout(120)->post($this->url('RequestTXN'), [
            'key' => $this->key,
            'nip' => $this->nip,
            'producto' => Arr::get($data,'producto'),
            'referencia' => Arr::get($data,'referencia'),
            'monto' => Arr::get($data,'monto')
        ]);
        throw_if($httpResponse->status() !== 200, new Exception(sprintf('%d error', $httpResponse->status())));
        $data = $httpResponse->json();
        throw_unless($data['success'], new Exception(sprintf(__('%s, error: %d'), $data['message'], $data['error'])));
        return Arr::get( Arr::get($data,'data') , 'transID');
    }

    /**
     * transaction_id
     * @param array $data
     * @return InformacionDeTransaccion
     * @throws Throwable
     */
    public function statusTxn(array $data) : InformacionDeTransaccion
    {
        if ($this->validate_online) throw_unless($this->isOnline(), ServerIsOffline::class);
        $validator = Validator::make($data, $this->statusTXN);
        throw_if($validator->fails(), ValidationException::class);
        $httpResponse = Http::asForm()->timeout(120)->post($this->url('StatusTXN'), [
            'key' => $this->key,
            'nip' => $this->nip,
            'transID' => Arr::get($data,'transaction_id')
        ]);
        throw_if($httpResponse->status() !== 200, new Exception(sprintf('%d error', $httpResponse->status())));
        $data = $httpResponse->json();
        throw_if(is_null($data), new Exception('Servicio no disponible'));
        throw_unless($data['success'], new Exception(sprintf(__('%s, error: %d'), $data['message'], $data['error'])));
        return new InformacionDeTransaccion($data['data']);
    }

    /**
     * 'producto' => 'required',
     * 'referencia' => 'required',
     * 'monto'    => 'required'
     * @return void
     */
    public function pagarServicio(array $data) : InformacionDeTransaccion
    {
        $transaction_id = $this->requestTxn($data);
        return $this->statusTxn(['transaction_id' => $transaction_id]);
    }

}