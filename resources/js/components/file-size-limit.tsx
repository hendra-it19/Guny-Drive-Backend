import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    id: string;
    value: string;
    max: number;
    error?: string;
    onChange: (value: string) => void;
};

export default function FileSizeLimit({
    id,
    value,
    max,
    error,
    onChange,
}: Props) {
    const unlimited = value === '0';
    const previous = useRef('');
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>Batas per berkas (MiB)</Label>
            <Input
                id={id}
                type="number"
                min="1"
                max={max}
                required
                disabled={unlimited}
                placeholder={unlimited ? 'Tanpa batas' : 'Masukkan ukuran'}
                value={unlimited ? '' : value}
                onChange={(e) =>
                    onChange(e.target.value === '0' ? '' : e.target.value)
                }
            />
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id + '-unlimited'}
                    checked={unlimited}
                    onCheckedChange={(checked) => {
                        if (checked === true) {
                            previous.current = value;
                            onChange('0');
                        } else {
                            onChange(previous.current);
                        }
                    }}
                />
                <Label htmlFor={id + '-unlimited'}>
                    Tanpa batas per berkas
                </Label>
            </div>
            <InputError message={error} />
        </div>
    );
}
