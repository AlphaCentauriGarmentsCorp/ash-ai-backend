<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;
use App\Models\ClientBrand;
use App\Models\FabricType;
use App\Models\TypeSize;
use App\Models\TypeGarment;
use App\Models\TypePrintingMethod;

class Order extends Model
{
    protected $table = 'orders';
    protected $fillable = [
        'po_number',
        'client_id',
        'brand_id',
        'channel',
        'order_type',
        'design_name',
        'type_fabric',
        'type_size',
        'type_garment',
        'type_printing_method',
        'design_files',
        'artist_filename',
        'mockup_url',
        'mockup_images',
        'mockup_notes',
        'print_location',
        'total_quantity',
        'size_breakdown',
        'target_date',
        'instruction_files',
        'instruction_notes',
        'unit_price',
        'desposit_percentage',
        'payment_terms',
        'currency',
        'status',
    ];

    // Relationships
    public function client() { return $this->belongsTo(Client::class, 'client_id'); }
    public function brand() { return $this->belongsTo(ClientBrand::class, 'brand_id'); }
    public function typeFabric() { return $this->belongsTo(FabricType::class, 'type_fabric'); }
    public function typeGarment() { return $this->belongsTo(TypeGarment::class, 'type_garment'); }
    public function typePrintingMethod() { return $this->belongsTo(TypePrintingMethod::class, 'type_printing_method'); }

}
